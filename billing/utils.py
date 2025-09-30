import secrets
from datetime import datetime
from django.utils import timezone
from passlib.hash import bcrypt
from .models import ApiKey

# Config
TOKEN_PREFIX_STR = "cg_live_"   # visible prefix in the raw token
PREFIX_LEN = 16                 # <= models.ApiKey.key_prefix max_length

def make_api_key():
    plain = TOKEN_PREFIX_STR + secrets.token_urlsafe(36)
    prefix = plain[:PREFIX_LEN]
    suffix = plain[PREFIX_LEN:]          # <- this is what we store in DB now
    return plain, prefix, suffix

def issue_api_key_for_user(*, user, plan="pro", tenant_id=None, customer_id=None):
    # revoke old active keys
    ApiKey.objects.filter(
        user=user, status="active", revoked_at__isnull=True
    ).update(status="revoked", revoked_at=timezone.now())

    # make new key (prefix + suffix)
    plain, prefix, suffix = make_api_key()

    # store prefix + plain_suffix
    ApiKey.objects.create(
        user=user,
        key_prefix=prefix,
        plain_suffix=suffix,              # <- ensure this is populated
        tenant_id=str(tenant_id or user.id),
        plan=plan,
        status="active",
        customer_id=customer_id or getattr(user, "stripe_customer_id", None),
    )
    return plain

def verify_token_in_db(token: str):
    """
    Verify an incoming raw token when we store prefix + suffix (plaintext).
    Returns the ApiKey row if valid & active, else None.
    """
    if not token:
        return None

    token = token.strip()
    if len(token) < PREFIX_LEN:
        return None

    prefix = token[:PREFIX_LEN]

    qs = (ApiKey.objects
          .filter(key_prefix=prefix, status="active", revoked_at__isnull=True)
          .order_by("-created_at"))

    for row in qs:
        expected = (row.key_prefix or "") + (row.plain_suffix or "")
        if token == expected:
            return row

    return None


def revoke_all_keys(user):
    """Helper: revoke all active keys for a user."""
    ApiKey.objects.filter(
        user=user, status="active", revoked_at__isnull=True
    ).update(status="revoked", revoked_at=timezone.now())
