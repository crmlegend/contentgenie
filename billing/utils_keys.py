# billing/utils_keys.py
from .models import ApiKey
from django.utils import timezone

def _issue_key_for_user(make_api_key_func, user=None, customer_id=None, plan="pro"):
    # revoke old keys
    if customer_id:
        ApiKey.objects.filter(customer_id=customer_id, status="active", revoked_at__isnull=True).update(
            status="revoked", revoked_at=timezone.now()
        )
    elif user:
        ApiKey.objects.filter(user=user, status="active", revoked_at__isnull=True).update(
            status="revoked", revoked_at=timezone.now()
        )

    # create new key
    plain, prefix, key_hash = make_api_key_func()
    ApiKey.objects.create(
        user=user,
        key_prefix=prefix,
        key_hash=key_hash,
        tenant_id=str((user.id if user else customer_id or "anon")),
        plan=plan,
        status="active",
        customer_id=customer_id
    )
    return plain
