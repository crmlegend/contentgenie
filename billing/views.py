

from django.views.decorators.csrf import csrf_exempt
from rest_framework.decorators import api_view, permission_classes
from rest_framework.permissions import IsAuthenticated, AllowAny
from django.contrib.auth.decorators import login_required
from rest_framework.response import Response
from django.conf import settings
from django.utils import timezone
from django.shortcuts import render
from django.contrib.auth import get_user_model
from django.http import HttpResponse
import logging
import stripe

from .models import ApiKey, WebhookEvent  # WebhookEvent optional; keep if you log events
from .utils import make_api_key

# Setup logging
logger = logging.getLogger(__name__)

# Get User model
User = get_user_model()

# Stripe API key (server-side secret key)
stripe.api_key = settings.STRIPE_SECRET_KEY

# Event types that trigger API key issuance
ACTIVE_EVENTS = {
    "checkout.session.completed",
    "invoice.payment_succeeded",
    "customer.subscription.created",
    "customer.subscription.updated",
}

# ---------- Helper functions ----------

def _revoke_active_keys_for_customer(customer_id: str):
    """Revoke all active API keys for a Stripe customer."""
    ApiKey.objects.filter(
        customer_id=customer_id,
        status="active",
        revoked_at__isnull=True,
    ).update(status="revoked", revoked_at=timezone.now())


def _revoke_active_keys_for_user(user: User):
    """Revoke all active API keys for a user."""
    ApiKey.objects.filter(
        user=user, status="active", revoked_at__isnull=True
    ).update(status="revoked", revoked_at=timezone.now())


def _issue_key_for_user(*, user: User | None, customer_id: str | None, plan="pro"):
    """
    Issue a fresh API key for a user or customer.
    Old keys are revoked. Returns the raw key (show/email once).
    """
    if customer_id:
        _revoke_active_keys_for_customer(customer_id)
    elif user:
        _revoke_active_keys_for_user(user)

    plain, prefix, key_hash = make_api_key()

    ApiKey.objects.create(
        user=user,
        key_prefix=prefix,
        key_hash=key_hash,
        tenant_id=str((user and user.id) or (customer_id or "anon")),
        plan=plan,
        status="active",
        customer_id=customer_id,
    )

    logger.info(
        "API key ISSUED: user=%s customer=%s plan=%s prefix=%s",
        getattr(user, "id", None), customer_id, plan, prefix
    )
    return plain


# ---------- Dashboard view ----------

@login_required
def dashboard(request):
    """
    Show dashboard page and display the latest API key prefix.
    """
    user = request.user
    latest_key = (
        ApiKey.objects
        .filter(user=user, status="active", revoked_at__isnull=True)
        .order_by("-created_at")
        .first()
    )
    latest_key_prefix = latest_key.key_prefix if latest_key else None

    return render(request, "dashboard.html", {
        "latest_key_prefix": latest_key_prefix,
    })


# ---------- Stripe Checkout ----------

@api_view(["POST"])
@permission_classes([IsAuthenticated])
def start_checkout(request):
    """
    Create a Stripe Checkout Session for subscription.
    """
    user: User = request.user

    # Ensure Stripe customer exists
    if not getattr(user, "stripe_customer_id", None):
        cust = stripe.Customer.create(
            email=user.email or None,
            metadata={"django_user_id": user.id},
        )
        user.stripe_customer_id = cust.id
        user.save(update_fields=["stripe_customer_id"])

    site = request.data.get("site") or "https://9d9cc56f9104.ngrok-free.app"
    success_url = f"{site}/dashboard/?sub=success"
    cancel_url = f"{site}/dashboard/?sub=cancel"

    session = stripe.checkout.Session.create(
        mode="subscription",
        customer=user.stripe_customer_id,
        line_items=[{"price": settings.STRIPE_PRICE_ID, "quantity": 1}],
        success_url=success_url,
        cancel_url=cancel_url,
    )
    print(success_url)
    return Response({"url": session.url})


# ---------- Stripe Webhook ----------

@csrf_exempt
@api_view(["POST"])
@permission_classes([AllowAny])
def stripe_webhook(request):
    """
    Stripe webhook endpoint: verify signature then enqueue event for background processing.
    """
    print("Stripe has sent the webhook")
    print("Success has occured, webhook hit ")

    # Use raw bytes for signature verification
    payload_bytes = request.body
    sig = request.headers.get("Stripe-Signature", "")

    try:
        event = stripe.Webhook.construct_event(
            payload_bytes, sig, settings.STRIPE_WEBHOOK_SECRET
        )
    except Exception as e:
        # Return the reason to make debugging easier (safe in test/development)
        return Response({"detail": str(e)}, status=400)

    # Helpful log to confirm which event type we got
    logger.info("Webhook OK: %s", event.get("type"))

    # ⬇️ Lazy-import here to avoid circular import at module import time
    from .tasks import process_stripe_event

    # Enqueue the Celery task. Your existing task expects a JSON string.
    process_stripe_event.delay(payload_bytes.decode("utf-8"))

    # Immediately respond to Stripe
    return Response({"received": True})


# ---------- View for API key (dashboard AJAX or API) ----------

@api_view(["GET"])
@permission_classes([IsAuthenticated])
def my_key(request):
    """
    Return the user's active key prefix and issue date (never raw key).
    """
    row = (
        ApiKey.objects
        .filter(user=request.user, status="active", revoked_at__isnull=True)
        .order_by("-created_at")
        .first()
    )
    if not row:
        return Response({"ok": False, "key": None})
    return Response({"ok": True, "key_prefix": row.key_prefix, "issued_at": row.created_at.isoformat()})


# ---------- Test webhook view ----------

def test_webhook(request):
    """
    Simple GET endpoint to test webhook forwarding.
    """
    print("Test webhook hit!")
    return HttpResponse("ok")
