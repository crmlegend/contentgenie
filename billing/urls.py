from django.urls import path
from .views import start_checkout, stripe_webhook, my_key, test_webhook  # ⬅ use real names

urlpatterns = [
    path("start/", start_checkout, name="start_checkout"),   # ⬅ points to start_checkout
    path("webhook/", stripe_webhook, name="stripe_webhook"),
    path("key/", my_key, name="my_key"),
    path("test/", test_webhook),
]
