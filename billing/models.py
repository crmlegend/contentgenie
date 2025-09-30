from django.db import models
from django.conf import settings
from django.utils import timezone


class ApiKey(models.Model):
    """
    Single source of truth for user API keys.
    Store only a hash of the full key. Show key_prefix on UI.
    Older keys are 'revoked' instead of hard-deleted to enable rotation.
    """

    user = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        null=True, blank=True,
        on_delete=models.SET_NULL,
        related_name="api_keys",
    )

    # What you can safely display
    key_prefix = models.CharField(max_length=16, db_index=True)

    # Never store the raw key — only a hash
    key_hash = models.CharField(max_length=255)

    # Optional bookkeeping
    tenant_id = models.CharField(max_length=128)
    plan = models.CharField(max_length=16, default="demo")

    # Lifecycle
    status = models.CharField(max_length=16, default="active")  # active | revoked
    created_at = models.DateTimeField(auto_now_add=True)
    revoked_at = models.DateTimeField(null=True, blank=True)

    # Stripe link (helps webhooks look up user)
    customer_id = models.CharField(max_length=128, null=True, blank=True)
    plain_suffix = models.TextField(null=True, blank=True)

    class Meta:
        ordering = ["-created_at"]
        indexes = [
            models.Index(fields=["user", "status"]),
            models.Index(fields=["customer_id"]),
            models.Index(fields=["key_prefix"]),
        ]

    def __str__(self):
        return f"{self.key_prefix} ({self.plan}/{self.status})"

    @property
    def is_active(self) -> bool:
        return self.status == "active" and self.revoked_at is None

    def revoke(self, when: timezone.datetime | None = None, save=True):
        self.status = "revoked"
        self.revoked_at = when or timezone.now()
        if save:
            self.save(update_fields=["status", "revoked_at"])


class WebhookEvent(models.Model):
    event_id = models.CharField(max_length=255, unique=True)
    kind = models.CharField(max_length=64)
    received_at = models.DateTimeField(auto_now_add=True)

    def __str__(self):
        return f"{self.kind}:{self.event_id}"
