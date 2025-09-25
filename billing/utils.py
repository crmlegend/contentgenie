import secrets
from datetime import datetime
from django.utils import timezone
from passlib.hash import bcrypt
from .models import ApiKey

# Config
TOKEN_PREFIX_STR = "cg_live_"   # visible prefix in the raw token
PREFIX_LEN = 16                 # <= models.ApiKey.key_prefix max_length

def make_api_key():
    """
    Generate a new API key.
    Returns (plain, prefix, key_hash).
    - plain: the full token to show once to the user
    - prefix: first PREFIX_LEN chars, safe to store/display
    - key_hash: bcrypt hash to store in DB (never store 'plain')
    """
    # token_urlsafe gives good entropy and URL-friendly characters
    plain = TOKEN_PREFIX_STR + secrets.token_urlsafe(36)
    prefix = plain[:PREFIX_LEN]
    key_hash = bcrypt.hash(plain)  # you can tweak rounds via bcrypt.using(rounds=12).hash(...)
    return plain, prefix, key_hash


def issue_api_key_for_user(*, user, plan="pro", tenant_id=None, customer_id=None):
    """
    Revoke the user's active keys and issue a fresh one.
    Creates a DB row and returns the RAW key string (show it once).
    """
    # 1) revoke existing active keys
    now = timezone.now()
    ApiKey.objects.filter(
        user=user,
        status="active",
        revoked_at__isnull=True,
    ).update(status="revoked", revoked_at=now)

    # 2) generate the new key
    plain, prefix, key_hash = make_api_key()

    # 3) create the DB row (store ONLY hash + prefix)
    ApiKey.objects.create(
        user=user,
        key_prefix=prefix,
        key_hash=key_hash,
        tenant_id=str(tenant_id or user.id),
        plan=plan,
        status="active",
        customer_id=customer_id or getattr(user, "stripe_customer_id", None),
    )

    # 4) return the RAW key so the caller can display/email it ONCE
    return plain


def verify_token_in_db(token: str):
    """
    Verify an incoming raw token.
    - First filter by prefix to avoid scanning all keys.
    - Then verify with bcrypt.
    Returns the ApiKey row if valid, else None.
    """
    if not token:
        return None

    prefix = token[:PREFIX_LEN]
    qs = ApiKey.objects.filter(
        key_prefix=prefix,
        status="active",
        revoked_at__isnull=True,
    )

    for row in qs:
        if bcrypt.verify(token, row.key_hash):
            return row
    return None


def revoke_all_keys(user):
    """Helper: revoke all active keys for a user."""
    ApiKey.objects.filter(
        user=user, status="active", revoked_at__isnull=True
    ).update(status="revoked", revoked_at=timezone.now())
