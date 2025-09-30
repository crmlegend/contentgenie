"""
URL configuration for core project.

The `urlpatterns` list routes URLs to views. For more information please see:
    https://docs.djangoproject.com/en/5.2/topics/http/urls/
Examples:
Function views
    1. Add an import:  from my_app import views
    2. Add a URL to urlpatterns:  path('', views.home, name='home')
Class-based views
    1. Add an import:  from other_app.views import Home
    2. Add a URL to urlpatterns:  path('', Home.as_view(), name='home')
Including another URLconf
    1. Import the include() function: from django.urls import include, path
    2. Add a URL to urlpatterns:  path('blog/', include('blog.urls'))
"""
from django.contrib import admin
# from django.urls import path
# from rest_framework_simplejwt.views import TokenObtainPairView, TokenRefreshView
# from accounts import views as acc_views
# from accounts import views as acc
from django.http import HttpResponse
from billing.views import verify_key 






# from django.contrib import admin
from django.urls import path, include
from rest_framework_simplejwt.views import TokenObtainPairView, TokenRefreshView
from accounts import views as acc_views
from billing import views as bill_views
from content import views as content_views
from django.contrib.auth import views as auth_views


def home(_):  # quick placeholder landing page
    return HttpResponse("Home")





# core/urls.py



from django.contrib import admin
from accounts.views import home, dashboard  # import the views

urlpatterns = [
    path("admin/", admin.site.urls),

    # Your accounts routes (custom first, then django's)
    path("accounts/", include("accounts.urls")),
    path("accounts/", include("django.contrib.auth.urls")),

    # pages
    path("", home, name="home"),                 # ← homepage at "/"
    path("dashboard/", dashboard, name="dashboard"),
]







urlpatterns = [
    path("", home, name="home"),
    path("admin/", admin.site.urls),
    path("accounts/", include("accounts.urls")), 
    path("accounts/", include("django.contrib.auth.urls")),  # login/logout/etc. 
    path("logout/", auth_views.LogoutView.as_view(next_page="login"), name="logout"),
      
    # path("admin/", admin.site.urls),

    # Accounts
    path("api/key/verify/", verify_key, name="verify_key"),
    path("auth/register", acc_views.register),
    path("auth/login", TokenObtainPairView.as_view()),
    path("auth/refresh", TokenRefreshView.as_view()),
    path("users/me", acc_views.me),
    path("dashboard/", dashboard, name="dashboard"), 
    path("", home, name="home"),     
    path("billing/", include("billing.urls")),

    # Billing
    path("v1/billing/checkout", bill_views.start_checkout),  # JWT-protected
    path("webhooks/stripe", bill_views.stripe_webhook),      # public (Stripe calls)
    path("billing/", include("billing.urls")),  # billing routes
    path("v1/keys/mine", bill_views.my_key),                 # JWT-protected
    
        # ... your admin/auth/billing routes ...
    path("v1/generate/content", content_views.generate),
    path("v1/blog/preview", content_views.blog_preview),
    
    path("dashboard/", bill_views.dashboard),
    path("profile/update", acc_views.profile_update),
]



