from rest_framework.permissions import BasePermission

class IsSubscriber(BasePermission):
    def has_permission(self, request, view):
        ctx = request.auth  # set by ApiKeyAuthentication
        return bool(ctx) and ctx.get("plan") in {"pro", "team"}
