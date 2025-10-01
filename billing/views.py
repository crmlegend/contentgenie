

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
from .utils import verify_token_in_db
from .utils import issue_api_key_for_user, make_api_key
from django.db.models import Q





from django.http import HttpResponse
from django.views.decorators.csrf import csrf_exempt
import logging, stripe
from django.conf import settings
from django.contrib.auth.decorators import login_required
from django.shortcuts import render
from .models import ApiKey

from .models import ApiKey, WebhookEvent  # WebhookEvent optional; keep if you log events
from .utils import make_api_key



logger = logging.getLogger(__name__)
stripe.api_key = settings.STRIPE_SECRET_KEY  # sk_test_... or sk_live_...


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





@api_view(["POST"])
@permission_classes([AllowAny])
def verify_key(request):
    """
    POST: {"key":"<raw api key>"}
    200 -> {"ok": true, "plan": "...", "key_prefix": "..."}
    401 -> {"ok": false}
    """
    raw = (request.data or {}).get("key", "")
    raw = (raw or "").strip()

    row = verify_token_in_db(raw)
    if not row:
        return Response({"ok": False}, status=401)

    return Response({
        "ok": True,
        "plan": row.plan,
        "key_prefix": row.key_prefix,
    })





# ---------- Dashboard view ----------

# billing/views.py



# @login_required
# def dashboard(request):
#     # identifiers we can match on
#     uid_str = str(request.user.id)
#     cust_id = getattr(request.user, "stripe_customer_id", None)
    
#     print(uid_str,cust_id)

#     # Build the OR condition:
#     q = (
#         Q(user=request.user) |
#         Q(tenant_id=uid_str)
#     )
#     if cust_id:
#         q |= Q(customer_id=cust_id) | Q(tenant_id=cust_id)

#     row = (
#         ApiKey.objects
#         .filter(q, status="active", revoked_at__isnull=True)
#         .order_by("-created_at")
#         .first()
#     )

#     full_key = None
#     ab="RaufAkbar"
#     if row:
#         # build “raw” key from the two columns you store
#         full_key = f"{row.key_prefix or ''}{getattr(row, 'plain_suffix', '')}"
#         print("Full key for user", request.user.id, "is", full_key)

#     return render(request, "dashboard.html", {
#         "key": row,               # template uses {{ key.plan }} / {% if key %}
#         "raw_api_key": full_key,
#         "cd": ab                   # template shows the full key if present
#     })

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

    site = request.data.get("site") or "https://djangosubscriptionpanel-app-e8cxfagthcf5emga.canadacentral-01.azurewebsites.net"
    success_url = f"{site}/accounts/dashboard/?sub=success" 
    cancel_url = f"{site}/accounts/dashboard/?sub=cancel"

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

# @csrf_exempt
# @api_view(["POST"])
# @permission_classes([AllowAny])
# def stripe_webhook(request):
#     """
#     Stripe webhook endpoint: verify signature then enqueue event for background processing.
#     """
#     print("Stripe has sent the webhook")
#     print("Success has occured, webhook hit ")

#     # Use raw bytes for signature verification
#     payload_bytes = request.body
#     sig = request.headers.get("Stripe-Signature", "")

#     try:
#         event = stripe.Webhook.construct_event(
#             payload_bytes, sig, settings.STRIPE_WEBHOOK_SECRET
#         )
#     except Exception as e:
#         # Return the reason to make debugging easier (safe in test/development)
#         return Response({"detail": str(e)}, status=400)

#     # Helpful log to confirm which event type we got
#     logger.info("Webhook OK: %s", event.get("type"))

#     # ⬇️ Lazy-import here to avoid circular import at module import time
#     from .tasks import process_stripe_event

#     # Enqueue the Celery task. Your existing task expects a JSON string.
#     process_stripe_event.delay(payload_bytes.decode("utf-8"))

#     # Immediately respond to Stripe
#     return Response({"received": True})








# @csrf_exempt
# def stripe_webhook(request):
#     # 1) Verify signature against the correct secret (TEST vs LIVE!)
#     payload = request.body
#     sig_header = request.META.get("HTTP_STRIPE_SIGNATURE", "")
#     secret = getattr(settings, "STRIPE_WEBHOOK_SECRET", None)

#     if not secret:
#         logger.error("STRIPE_WEBHOOK_SECRET not set")
#         return HttpResponse(status=400)

#     try:
#         event = stripe.Webhook.construct_event(payload, sig_header, secret)
#     except ValueError as e:
#         # Invalid JSON
#         logger.warning("Invalid payload: %s", e)
#         return HttpResponse(status=400)
#     except stripe.error.SignatureVerificationError as e:
#         # Wrong secret / missing header
#         logger.warning("Signature verify failed: %s", e)
#         return HttpResponse(status=400)

#     # 2) Handle only what's needed, keep it FAST
#     try:
#         if event["type"] == "checkout.session.completed":
#             # enqueue if available; if queue fails, just log and ACK
#             try:
#                 from .tasks import process_stripe_event  # lazy import
#                 process_stripe_event.delay(payload.decode("utf-8"))
#             except Exception as enqueue_err:
#                 logger.exception("Failed to enqueue webhook task: %s", enqueue_err)
#                 # optional: persist raw event for later manual processing

#         # handle other events similarly if you need them...

#     except Exception as handler_err:
#         # Never 500 to Stripe; log instead
#         logger.exception("Webhook handler error: %s", handler_err)

#     # 3) ALWAYS acknowledge quickly so Stripe stops retrying
#     return HttpResponse(status=200)


















@csrf_exempt
def stripe_webhook(request):
    """
    Verify Stripe signature. On checkout.session.completed, issue API key *inline*.
    Keep this handler very fast (<10s) so Stripe doesn't retry.
    """
    payload = request.body
    sig_header = request.META.get("HTTP_STRIPE_SIGNATURE", "")
    secret = settings.STRIPE_WEBHOOK_SECRET

    # 1) Verify the signature
    try:
        event = stripe.Webhook.construct_event(payload, sig_header, secret)
    except Exception as e:
        logger.warning("webhook verify failed: %s", e)
        return HttpResponse(status=400)

    evt_type = event.get("type")
    obj = (event.get("data") or {}).get("object") or {}

    # 2) (Optional) Idempotency: ignore duplicates if Stripe retries
    # If you have a WebhookEvent table, check event["id"] here and skip if seen.

    # 3) Only issue on successful checkout (you can add more types if you want)
    if evt_type == "checkout.session.completed":
        customer_id = obj.get("customer")

        # Try to find the user by customer_id first (you saved it on the user at checkout)
        user = None
        if customer_id:
            user = User.objects.filter(stripe_customer_id=customer_id).first()
        if not user:
            # Fallback to email if present
            email = (obj.get("customer_details") or {}).get("email") or obj.get("customer_email")
            if email:
                user = User.objects.filter(email__iexact=email).first()

        if user or customer_id:
            try:
                issue_api_key_for_user(
                    user=user,
                    customer_id=customer_id,
                    plan="pro",
                    # make_api_key_func=make_api_key,   # your helper expects this
                )
                logger.info("API key issued inline: user=%s customer=%s", 
                            getattr(user, "id", None), customer_id)
            except Exception as e:
                logger.exception("inline key issue failed: %s", e)
                # Return 200 anyway so Stripe doesn't keep retrying forever
        else:
            logger.warning("webhook: no matching user for customer=%s", customer_id)

    # 4) Always ACK quickly
    return HttpResponse(status=200)










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
