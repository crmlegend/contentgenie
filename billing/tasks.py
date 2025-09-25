from celery import shared_task
from .views import _issue_key_for_user
from django.contrib.auth import get_user_model
import json
from .utils_keys import _issue_key_for_user
from .utils import make_api_key


User = get_user_model()

@shared_task
def process_stripe_event(payload_str):
    """
    Task to process Stripe webhook events and issue API keys.
    """
    print('Webhook Receieved')
    event = json.loads(payload_str)
    evt_type = event.get("type")
    data = event.get("data", {}).get("object", {})

    customer_id = data.get("customer")
    email = (data.get("customer_details") or {}).get("email")

    user = None
    if customer_id:
        user = User.objects.filter(stripe_customer_id=customer_id).first()
    if not user and email:
        user = User.objects.filter(email=email).first()

    if user and evt_type in {
        "checkout.session.completed",
        "invoice.payment_succeeded",
        "customer.subscription.created",
        "customer.subscription.updated",
    }:
        _issue_key_for_user(make_api_key_func=make_api_key,user=user, customer_id=customer_id, plan="pro")
