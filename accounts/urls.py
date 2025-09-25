
from django.urls import path
from .views import SignUpView, NiceLoginView, dashboard


urlpatterns = [
    path("signup/", SignUpView.as_view(), name="signup"),
    path("login/", NiceLoginView.as_view(), name="login"),  # overrides contrib’s default
    path("dashboard/", dashboard, name="dashboard"),
]
