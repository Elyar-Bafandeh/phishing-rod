#!/usr/bin/env python3
from __future__ import annotations

import math
import re
from pathlib import Path
from urllib.parse import urlparse, parse_qs

import pandas as pd

try:
    import tldextract
except ImportError as e:
    raise SystemExit("Missing dependency. Install with: pip install tldextract") from e


SUSPICIOUS_WORDS = [
    "login", "signin", "sign-in", "verify", "verification", "secure", "account",
    "update", "confirm", "password", "bank", "billing", "invoice", "payment",
    "pay", "paypal", "security", "unlock", "limited", "suspend", "support",
    "service", "alert", "identity", "recover",
]

SHORTENER_DOMAINS = {
    "bit.ly", "tinyurl.com", "goo.gl", "t.co", "ow.ly", "is.gd", "buff.ly",
    "cutt.ly", "rebrand.ly", "tiny.cc", "rb.gy",
}

IPV4_RE = re.compile(r"^(?:\d{1,3}\.){3}\d{1,3}$")
HEX_IP_RE = re.compile(r"0x[0-9a-fA-F]+")


def shannon_entropy(s: str) -> float:
    if not s:
        return 0.0
    freq: dict[str, int] = {}
    for ch in s:
        freq[ch] = freq.get(ch, 0) + 1
    ent = 0.0
    n = len(s)
    for c in freq.values():
        p = c / n
        ent -= p * math.log2(p)
    return ent


def safe_parse(url: str):
    """Parse URL; if scheme missing, assume http for stable parsing."""
    if not isinstance(url, str):
        url = "" if pd.isna(url) else str(url)
    u = url.strip()
    if not u:
        return "", urlparse("")
    if "://" not in u:
        u = "http://" + u
    return u, urlparse(u)


def is_ip_host(host: str) -> int:
    if not host:
        return 0
    host = host.strip().lower()
    if IPV4_RE.match(host):
        try:
            parts = host.split(".")
            return 1 if all(0 <= int(p) <= 255 for p in parts) else 0
        except ValueError:
            return 0
    if HEX_IP_RE.search(host):
        return 1
    return 0


def extract_url_features(url: str) -> dict:
    raw_url, p = safe_parse(url)
    host = (p.hostname or "").lower()
    path = p.path or ""
    query = p.query or ""
    fragment = p.fragment or ""

    ext = tldextract.extract(host)  # subdomain, domain, suffix
    registered_domain = ".".join([x for x in [ext.domain, ext.suffix] if x]) or ""

    num_digits = sum(ch.isdigit() for ch in raw_url)
    num_letters = sum(ch.isalpha() for ch in raw_url)

    qs = parse_qs(query, keep_blank_values=True)
    num_params = len(qs)
    total_param_values = sum(len(v) for v in qs.values()) if qs else 0

    sub = ext.subdomain or ""
    subdomain_labels = len([x for x in sub.split(".") if x])

    path_depth = len([seg for seg in path.split("/") if seg])

    lower = raw_url.lower()
    suspicious_word_count = sum(1 for w in SUSPICIOUS_WORDS if w in lower)

    is_shortener = 1 if registered_domain.lower() in SHORTENER_DOMAINS else 0

    return {
        # lengths
        "url_length": len(raw_url),
        "hostname_length": len(host),
        "path_length": len(path),
        "query_length": len(query),
        "fragment_length": len(fragment),

        # character counts (common signals)
        "count_dots": raw_url.count("."),
        "count_hyphens": raw_url.count("-"),
        "count_underscores": raw_url.count("_"),
        "count_slashes": raw_url.count("/"),
        "count_questionmarks": raw_url.count("?"),
        "count_equals": raw_url.count("="),
        "count_amps": raw_url.count("&"),
        "count_at": raw_url.count("@"),
        "count_percent": raw_url.count("%"),

        # composition
        "num_digits": num_digits,
        "num_letters": num_letters,

        # structure flags
        "has_https": 1 if p.scheme.lower() == "https" else 0,
        "has_ip_host": is_ip_host(host),
        "has_port": 1 if (p.port is not None) else 0,
        "has_query": 1 if query else 0,
        "has_fragment": 1 if fragment else 0,

        # hierarchical / complexity
        "subdomain_labels": subdomain_labels,
        "path_depth": path_depth,
        "num_params": num_params,
        "total_param_values": total_param_values,

        # domain parts
        "domain": ext.domain or "",
        "suffix": ext.suffix or "",
        "registered_domain": registered_domain,

        # heuristics
        "suspicious_word_count": suspicious_word_count,
        "is_shortener_domain": is_shortener,

        # randomness-ish
        "url_entropy": shannon_entropy(raw_url),
        "host_entropy": shannon_entropy(host),
    }

def read_table(path: Path) -> pd.DataFrame:
    # Try normal fast parser first
    try:
        return pd.read_csv(path)
    except Exception:
        # Fallback: more tolerant parser
        return pd.read_csv(
            path,
            engine="python",        # more forgiving than C engine
            on_bad_lines="skip",    # skip malformed rows (or use "warn")
            dtype=str,              # avoid dtype parsing issues
            encoding="utf-8",
        )
    

def main() -> int:
    # File location: src/phishing_detector/features/url_features.py
    THIS_FILE = Path(__file__).resolve()

    # repo root: .../phishing-detector
    REPO_ROOT = THIS_FILE.parents[3]

    # project data paths (inside src/phishing_detector/data)
    PKG_DATA_DIR = REPO_ROOT / "src" / "phishing_detector" / "data"
    RAW_DIR = PKG_DATA_DIR / "raw"
    PROCESSED_DIR = PKG_DATA_DIR / "processed"
    PROCESSED_DIR.mkdir(parents=True, exist_ok=True)

    # input / output files
    IN_PATH = RAW_DIR / "your_table.csv"
    OUT_PATH = PROCESSED_DIR / "your_table_url_features.csv"

    print(IN_PATH)
    if not IN_PATH.exists():
        
        raise SystemExit(f"Input not found: {IN_PATH}")

    df = read_table(IN_PATH)

    if "url" not in df.columns:
        raise SystemExit(f"Expected a 'url' column. Found: {list(df.columns)}")

    feat_df = pd.DataFrame(df["url"].apply(extract_url_features).tolist())

    # keep common metadata columns if they exist
    keep_cols = [c for c in ["rec_id", "website", "result", "created_date"] if c in df.columns]
    out_df = pd.concat([df[keep_cols].reset_index(drop=True), feat_df.reset_index(drop=True)], axis=1)

    out_df.to_csv(OUT_PATH, index=False)

    print(f"Read : {IN_PATH}")
    print(f"Wrote: {OUT_PATH} (rows={len(out_df)}, features={feat_df.shape[1]})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
