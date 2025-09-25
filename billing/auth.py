from rest_framework.authentication import BaseAuthentication
from rest_framework.exceptions import AuthenticationFailed
from django.conf import settings
from .utils import verify_token_in_db

class ApiKeyAuthentication(BaseAuthentication):
    """
    Subscriber-only auth for /v1/* routes.
    Requires: Authorization: Bearer <PLAINTEXT_API_KEY>
    """
    def authenticate(self, request):
        # Only enforce on the product API paths
        if not request.path.startswith("/v1/"):
            return None

        auth = request.headers.get("Authorization", "")
        if not auth.startswith("Bearer "):
            raise AuthenticationFailed("Missing or invalid Authorization header")

        token = auth.split(" ", 1)[1].strip()

        row = verify_token_in_db(token)
        if row and row.status == "active":
            # request.user stays None (machine token), plan/tenant in request.auth
            return (None, {"tenant_id": row.tenant_id, "plan": row.plan})

        # Dev/test override
        if token == settings.TEST_KEY:
            return (None, {"tenant_id": "dev", "plan": "demo"})

        raise AuthenticationFailed("Invalid or inactive key")
