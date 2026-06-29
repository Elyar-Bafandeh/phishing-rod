"""Loads and caches the runtime .joblib models.

Each model is an sklearn ``Pipeline`` (median imputer + RandomForest) saved with
scikit-learn 1.8.0. Models are loaded lazily on first use and cached in memory
for the life of the process.

Security: only the two active models may ever be loaded. The deprecated
``best_html_model.joblib`` and the ``best_combined_model.joblib`` (trained on the
old HTML feature set, not used in the two-model approach) are rejected.
"""

import threading
from pathlib import Path
from typing import Any

import joblib

from .config import get_settings

URL_MODEL = "best_url_model.joblib"
HTML_MODEL = "best_html_enhanced_model.joblib"

# The only models that may be loaded at runtime.
ALLOWED_MODELS = frozenset({URL_MODEL, HTML_MODEL})

# Explicitly recognised-but-rejected models, for clearer error messages.
REJECTED_MODELS = {
    "best_html_model.joblib": "deprecated (replaced by the enhanced HTML model)",
    "best_combined_model.joblib": "not used at runtime (trained on the deprecated HTML feature set)",
}

_cache: dict[str, Any] = {}
_lock = threading.Lock()


class ModelError(RuntimeError):
    """Raised for any controlled model-loading failure."""


def load_model(name: str) -> Any:
    """Return the cached model for ``name``, loading it on first use.

    Raises ModelError if the name is not allowed or the file is missing.
    """
    if name not in ALLOWED_MODELS:
        reason = REJECTED_MODELS.get(name, "unknown model")
        raise ModelError(f"Model not allowed at runtime: {name} ({reason}).")

    cached = _cache.get(name)
    if cached is not None:
        return cached

    with _lock:
        # Re-check inside the lock in case another thread just loaded it.
        cached = _cache.get(name)
        if cached is not None:
            return cached

        path = Path(get_settings().ml_models_dir) / name
        if not path.is_file():
            raise ModelError(f"Model file not found: {path}")

        model = joblib.load(path)
        _cache[name] = model
        return model
