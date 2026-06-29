import os
import re
import math
import argparse
from collections import Counter
from urllib.parse import urlparse, urljoin

import pandas as pd
from bs4 import BeautifulSoup, Comment


SUSPICIOUS_KEYWORDS = [
    "login", "signin", "sign in", "verify", "verification", "update",
    "confirm", "secure", "account", "bank", "password", "credential",
    "wallet", "payment", "invoice", "urgent", "suspended", "limited",
    "security", "recover", "reset", "ssn", "tax", "otp", "2fa"
]

BRAND_KEYWORDS = [
    "paypal", "apple", "microsoft", "amazon", "google", "facebook",
    "instagram", "netflix", "bank", "outlook", "office365", "dhl", "ups"
]

# Common credential/exfiltration endpoint names seen in simple phishing kits.
SUSPICIOUS_ENDPOINT_PATTERNS = [
    r"(?:^|/)(?:send|post|gate|mailer|collect|login|check|validate|verify|auth|submit)\.php(?:[?\s'\"<>)]|$)",
    r"(?:^|/)(?:api/)?(?:login|collect|credential|credentials|submit)(?:\?|/|$)",
]

CSRF_NAME_PATTERNS = ["csrf", "xsrf", "token", "authenticity_token", "_token"]
SSO_NAME_PATTERNS = ["saml", "oauth", "openid", "state", "nonce", "client_id", "redirect_uri"]
KIT_ARTIFACT_PATTERNS = [
    r"phishing", r"x-login-kit", r"login-kit", r"panel", r"campaign", r"victim",
    r"telegram", r"bot_token", r"chat_id", r"mailer", r"scam", r"blackeye", r"zphisher"
]


def safe_read_html(file_path: str, max_bytes: int = 5_000_000) -> str:
    """
    Read HTML as plain text only.
    No rendering, no JS execution, no external requests.
    """
    try:
        file_size = os.path.getsize(file_path)
        if file_size > max_bytes:
            return ""
        with open(file_path, "rb") as f:
            raw = f.read(max_bytes)
        return raw.decode("utf-8", errors="ignore")
    except Exception:
        return ""


def shannon_entropy(text: str) -> float:
    if not text:
        return 0.0
    counts = Counter(text)
    length = len(text)
    return -sum((c / length) * math.log2(c / length) for c in counts.values())


def count_regex(pattern: str, text: str, flags=0) -> int:
    return len(re.findall(pattern, text, flags))


def get_domain(url: str) -> str:
    """Return normalized host/netloc without userinfo, port, or leading www."""
    try:
        parsed = urlparse(str(url).strip())
        hostname = parsed.hostname or ""
        hostname = hostname.lower().strip(".")
        if hostname.startswith("www."):
            hostname = hostname[4:]
        return hostname
    except Exception:
        return ""


def resolve_url(raw_url: str, page_url: str) -> str:
    raw_url = str(raw_url or "").strip()
    if not raw_url:
        return ""
    try:
        return urljoin(page_url, raw_url)
    except Exception:
        return raw_url


def is_external_url(raw_url: str, page_url: str, base_domain: str) -> int:
    try:
        resolved = resolve_url(raw_url, page_url)
        target_domain = get_domain(resolved)
        if not target_domain or not base_domain:
            return 0
        return int(target_domain != base_domain)
    except Exception:
        return 0


def get_attr_domain(tag, attr_name: str, page_url: str) -> str:
    value = tag.get(attr_name) if tag else None
    if not value:
        return ""
    return get_domain(resolve_url(value, page_url))


def empty_feature_dict() -> dict:
    """All output columns with safe zero defaults, including the newly added features."""
    return {
        "html_bytes": 0,
        "html_char_count": 0,
        "text_char_count": 0,
        "text_word_count": 0,
        "html_entropy": 0.0,
        "tag_count": 0,
        "unique_tag_count": 0,
        "script_count": 0,
        "noscript_count": 0,
        "iframe_count": 0,
        "form_count": 0,
        "input_count": 0,
        "password_input_count": 0,
        "hidden_input_count": 0,
        "button_count": 0,
        "anchor_count": 0,
        "img_count": 0,
        "meta_count": 0,
        "link_tag_count": 0,
        "style_tag_count": 0,
        "title_present": 0,
        "title_length": 0,
        "has_favicon": 0,
        "has_meta_refresh": 0,
        "has_onclick": 0,
        "has_onload": 0,
        "has_eval": 0,
        "has_escape": 0,
        "has_unescape": 0,
        "has_window_open": 0,
        "mailto_count": 0,
        "tel_count": 0,
        "javascript_href_count": 0,
        "empty_href_count": 0,
        "internal_link_count": 0,
        "external_link_count": 0,
        "null_link_count": 0,
        "relative_link_count": 0,
        "https_link_count": 0,
        "malformed_url_count": 0,
        "suspicious_keyword_count": 0,
        "brand_keyword_count": 0,
        "has_login_form": 0,
        "copyright_symbol_count": 0,
        "comment_count": 0,
        "redirect_keyword_count": 0,

        # New Tier 1 features from the comparison report
        "form_action_external_count": 0,
        "form_action_empty_count": 0,
        "form_action_http_count": 0,
        "external_script_count": 0,
        "has_fetch": 0,
        "has_xmlhttprequest": 0,
        "has_formdata": 0,
        "has_submit_listener": 0,

        # New Tier 2 / stronger phishing-copycat indicators
        "external_img_count": 0,
        "external_css_count": 0,
        "hotlinked_asset_count": 0,
        "has_telegram_webhook": 0,
        "has_discord_webhook": 0,
        "suspicious_php_endpoint_count": 0,
        "has_base_domain_mismatch": 0,
        "has_canonical_domain_mismatch": 0,
        "has_meta_refresh_domain_mismatch": 0,
        "csrf_token_count": 0,
        "nonce_attribute_count": 0,
        "sso_parameter_count": 0,
        "missing_auth_token_in_login_form": 0,
        "phishing_kit_comment_count": 0,
        "has_fake_validation_text": 0,
    }


def extract_html_features(html: str, url: str) -> dict:
    """
    Static parse only.
    BeautifulSoup parses markup as text structure; it does not execute JS.
    """
    features = empty_feature_dict()
    base_domain = get_domain(url)

    if not html.strip():
        return features

    soup = BeautifulSoup(html, "html.parser")
    text = soup.get_text(" ", strip=True).lower()
    html_lower = html.lower()

    all_tags = [tag.name for tag in soup.find_all()]
    anchors = soup.find_all("a")
    forms = soup.find_all("form")
    inputs = soup.find_all("input")
    scripts = soup.find_all("script")
    imgs = soup.find_all("img")
    link_tags = soup.find_all("link")

    password_inputs = 0
    hidden_inputs = 0
    csrf_token_count = 0
    sso_parameter_count = 0

    for inp in inputs:
        inp_type = (inp.get("type") or "").strip().lower()
        inp_name = (inp.get("name") or inp.get("id") or "").strip().lower()
        if inp_type == "password":
            password_inputs += 1
        if inp_type == "hidden":
            hidden_inputs += 1
        if any(pattern in inp_name for pattern in CSRF_NAME_PATTERNS):
            csrf_token_count += 1
        if any(pattern in inp_name for pattern in SSO_NAME_PATTERNS):
            sso_parameter_count += 1

    internal_link_count = 0
    external_link_count = 0
    null_link_count = 0
    relative_link_count = 0
    javascript_href_count = 0
    empty_href_count = 0
    https_link_count = 0
    mailto_count = 0
    tel_count = 0
    malformed_url_count = 0

    for a in anchors:
        href_original = (a.get("href") or "").strip()
        href = href_original.lower()

        if not href:
            empty_href_count += 1
            continue

        if href in ["#", "#content", "#main", "javascript:void(0)", "javascript:;", "about:blank"]:
            null_link_count += 1

        if href.startswith("javascript:"):
            javascript_href_count += 1
        elif href.startswith("mailto:"):
            mailto_count += 1
        elif href.startswith("tel:"):
            tel_count += 1
        elif href.startswith("https://"):
            https_link_count += 1

        try:
            parsed = urlparse(href_original)
        except Exception:
            malformed_url_count += 1
            continue

        if not parsed.scheme and not parsed.netloc:
            relative_link_count += 1

        href_domain = get_domain(resolve_url(href_original, url))
        if href_domain:
            if href_domain == base_domain:
                internal_link_count += 1
            else:
                external_link_count += 1
        else:
            internal_link_count += 1

    # New: form-action analysis.
    form_action_external_count = 0
    form_action_empty_count = 0
    form_action_http_count = 0
    missing_auth_token_in_login_form = 0

    for form in forms:
        action = (form.get("action") or "").strip()
        if not action:
            form_action_empty_count += 1
        else:
            resolved_action = resolve_url(action, url)
            action_domain = get_domain(resolved_action)
            action_scheme = urlparse(resolved_action).scheme.lower()
            if action_scheme == "http":
                form_action_http_count += 1
            if action_domain and base_domain and action_domain != base_domain:
                form_action_external_count += 1

        form_html = str(form).lower()
        is_login_like = "password" in form_html or "login" in form_html or "signin" in form_html or "sign in" in form_html
        has_auth_token = any(token in form_html for token in CSRF_NAME_PATTERNS + SSO_NAME_PATTERNS)
        if is_login_like and not has_auth_token:
            missing_auth_token_in_login_form += 1

    # New: external scripts, images, and CSS assets.
    external_script_count = sum(is_external_url(script.get("src"), url, base_domain) for script in scripts if script.get("src"))
    external_img_count = sum(is_external_url(img.get("src"), url, base_domain) for img in imgs if img.get("src"))
    external_css_count = 0
    for link in link_tags:
        rel = " ".join(link.get("rel") or []).lower() if isinstance(link.get("rel"), list) else str(link.get("rel") or "").lower()
        href = link.get("href")
        if "stylesheet" in rel and href:
            external_css_count += is_external_url(href, url, base_domain)

    # New: base/canonical/refresh destination mismatch.
    base_tag = soup.find("base", href=True)
    canonical_tag = soup.find("link", rel=lambda x: x and "canonical" in str(x).lower(), href=True)
    base_domain_tag = get_attr_domain(base_tag, "href", url)
    canonical_domain = get_attr_domain(canonical_tag, "href", url)

    meta_refresh_domain_mismatch = 0
    for meta in soup.find_all("meta", attrs={"http-equiv": re.compile(r"refresh", re.I)}):
        content = meta.get("content") or ""
        match = re.search(r"url\s*=\s*['\"]?([^'\";]+)", content, flags=re.I)
        if match:
            refresh_domain = get_domain(resolve_url(match.group(1), url))
            if refresh_domain and base_domain and refresh_domain != base_domain:
                meta_refresh_domain_mismatch = 1
                break

    # New: comments and kit artifacts.
    comments = [str(c).lower() for c in soup.find_all(string=lambda text_node: isinstance(text_node, Comment))]
    phishing_kit_comment_count = sum(
        1 for comment in comments
        if any(re.search(pattern, comment, flags=re.I) for pattern in KIT_ARTIFACT_PATTERNS)
    )

    title_tag = soup.title.string.strip() if soup.title and soup.title.string else ""
    suspicious_keyword_count = sum(text.count(k) for k in SUSPICIOUS_KEYWORDS)
    brand_keyword_count = sum(text.count(k) for k in BRAND_KEYWORDS)

    has_login_form = 0
    for form in forms:
        form_text = form.get_text(" ", strip=True).lower()
        form_html = str(form).lower()
        if "password" in form_html or "login" in form_text or "signin" in form_text or "sign in" in form_text:
            has_login_form = 1
            break

    suspicious_php_endpoint_count = sum(
        count_regex(pattern, html_lower, flags=re.I) for pattern in SUSPICIOUS_ENDPOINT_PATTERNS
    )

    features.update({
        "html_bytes": len(html.encode("utf-8", errors="ignore")),
        "html_char_count": len(html),
        "text_char_count": len(text),
        "text_word_count": len(text.split()),
        "html_entropy": shannon_entropy(html_lower),

        "tag_count": len(all_tags),
        "unique_tag_count": len(set(all_tags)),
        "script_count": len(scripts),
        "noscript_count": len(soup.find_all("noscript")),
        "iframe_count": len(soup.find_all("iframe")),
        "form_count": len(forms),
        "input_count": len(inputs),
        "password_input_count": password_inputs,
        "hidden_input_count": hidden_inputs,
        "button_count": len(soup.find_all("button")),
        "anchor_count": len(anchors),
        "img_count": len(imgs),
        "meta_count": len(soup.find_all("meta")),
        "link_tag_count": len(link_tags),
        "style_tag_count": len(soup.find_all("style")),

        "title_present": int(bool(title_tag)),
        "title_length": len(title_tag),
        "has_favicon": int(bool(soup.find("link", rel=lambda x: x and "icon" in str(x).lower()))),
        "has_meta_refresh": int(bool(soup.find("meta", attrs={"http-equiv": re.compile(r"refresh", re.I)}))),

        "has_onclick": int("onclick=" in html_lower),
        "has_onload": int("onload=" in html_lower),
        "has_eval": int("eval(" in html_lower),
        "has_escape": int("escape(" in html_lower),
        "has_unescape": int("unescape(" in html_lower),
        "has_window_open": int("window.open(" in html_lower),

        "mailto_count": mailto_count,
        "tel_count": tel_count,
        "javascript_href_count": javascript_href_count,
        "empty_href_count": empty_href_count,
        "internal_link_count": internal_link_count,
        "external_link_count": external_link_count,
        "null_link_count": null_link_count,
        "relative_link_count": relative_link_count,
        "https_link_count": https_link_count,
        "malformed_url_count": malformed_url_count,

        "suspicious_keyword_count": suspicious_keyword_count,
        "brand_keyword_count": brand_keyword_count,
        "has_login_form": has_login_form,
        "copyright_symbol_count": html.count("©") + html.lower().count("&copy;"),
        "comment_count": len(comments),
        "redirect_keyword_count": sum(text.count(k) for k in ["redirect", "continue", "verify", "validate"]),

        # New Tier 1 features
        "form_action_external_count": form_action_external_count,
        "form_action_empty_count": form_action_empty_count,
        "form_action_http_count": form_action_http_count,
        "external_script_count": external_script_count,
        "has_fetch": int(bool(re.search(r"\bfetch\s*\(", html_lower))),
        "has_xmlhttprequest": int("xmlhttprequest" in html_lower),
        "has_formdata": int(bool(re.search(r"\bformdata\s*\(", html_lower))),
        "has_submit_listener": int(bool(re.search(r"addEventListener\s*\(\s*['\"]submit['\"]", html, flags=re.I))),

        # New Tier 2 / stronger phishing indicators
        "external_img_count": external_img_count,
        "external_css_count": external_css_count,
        "hotlinked_asset_count": external_img_count + external_css_count,
        "has_telegram_webhook": int("api.telegram.org" in html_lower or "telegram" in html_lower and "chat_id" in html_lower),
        "has_discord_webhook": int("discord.com/api/webhooks" in html_lower or "discordapp.com/api/webhooks" in html_lower),
        "suspicious_php_endpoint_count": suspicious_php_endpoint_count,
        "has_base_domain_mismatch": int(bool(base_domain_tag and base_domain and base_domain_tag != base_domain)),
        "has_canonical_domain_mismatch": int(bool(canonical_domain and base_domain and canonical_domain != base_domain)),
        "has_meta_refresh_domain_mismatch": meta_refresh_domain_mismatch,
        "csrf_token_count": csrf_token_count,
        "nonce_attribute_count": len(soup.find_all(attrs={"nonce": True})),
        "sso_parameter_count": sso_parameter_count,
        "missing_auth_token_in_login_form": missing_auth_token_in_login_form,
        "phishing_kit_comment_count": phishing_kit_comment_count,
        "has_fake_validation_text": int(any(k in text for k in ["verifying", "validating", "please wait", "incorrect password", "try again"])),
    })

    return features


def process_html_folder(html_dir: str, table_csv: str, output_csv: str):
    df = pd.read_csv(table_csv, engine="python", on_bad_lines="skip")

    required_cols = {"url", "website", "result"}
    missing = required_cols - set(df.columns)
    if missing:
        raise ValueError(f"Missing required columns in table CSV: {missing}")

    # Normalize filenames for matching
    df["website"] = df["website"].astype(str).str.strip()
    table_map = df.set_index("website").to_dict(orient="index")

    rows_to_write = []
    processed = 0
    matched = 0
    skipped = 0

    for root, _, files in os.walk(html_dir):
        for fname in files:
            lower = fname.lower()
            if not lower.endswith((".html", ".htm")):
                continue

            processed += 1
            if fname not in table_map:
                skipped += 1
                continue

            record = table_map[fname]
            url = str(record["url"])
            label = record["result"]

            file_path = os.path.join(root, fname)
            html = safe_read_html(file_path)
            feats = extract_html_features(html, url)

            row = {
                "website": fname,
                "url": url,
                "label": label,
                "rec_id": record.get("rec_id"),
                "created_date": record.get("created_date"),
                **feats
            }

            rows_to_write.append(row)
            matched += 1

    if not rows_to_write:
        print("No matching HTML files found for this folder.")
        print(f"Processed files: {processed}, matched: {matched}, skipped: {skipped}")
        return

    out_df = pd.DataFrame(rows_to_write)

    # Append across multiple dataset parts
    write_header = not os.path.exists(output_csv)
    out_df.to_csv(output_csv, mode="a", index=False, header=write_header)

    print(f"Processed HTML files: {processed}")
    print(f"Matched to table:     {matched}")
    print(f"Skipped (no match):   {skipped}")
    print(f"Rows appended to:     {output_csv}")


def main():
    parser = argparse.ArgumentParser(description="Offline HTML feature extractor with phishing-copycat indicators")
    parser.add_argument("--html-dir", required=True, help="Path to extracted HTML folder")
    parser.add_argument("--table-csv", required=True, help="Path to URL table CSV")
    parser.add_argument("--output-csv", required=True, help="Path to output feature CSV")
    args = parser.parse_args()

    process_html_folder(
        html_dir=args.html_dir,
        table_csv=args.table_csv,
        output_csv=args.output_csv
    )


if __name__ == "__main__":
    main()
