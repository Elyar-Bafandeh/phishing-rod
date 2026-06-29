"""Bearer-token authentication for the internal ML service.

The service must never be publicly reachable; this dependency is the
in-process guard that rejects any request not carrying the shared token the
Laravel backend was configured with.
"""

import secrets

from fastapi import Header, HTTPException, status

from .config import get_settings


def require_token(authorization: str = Header(default="")) -> None:
    """Reject the request unless it carries the correct bearer token.

    Uses a constant-time comparison to avoid leaking the token via timing.
    """
    settings = get_settings()

    scheme, _, token = authorization.partition(" ")
    is_valid = (
        scheme.lower() == "bearer"
        and secrets.compare_digest(token, settings.ml_service_token)
    )

    if not is_valid:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing bearer token.",
        )
