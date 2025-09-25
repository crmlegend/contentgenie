from django.contrib import admin
from django.contrib.auth.admin import UserAdmin as DjangoUserAdmin
from .models import User

@admin.register(User)
class UserAdmin(DjangoUserAdmin):
    fieldsets = DjangoUserAdmin.fieldsets + (
        ("Billing", {"fields": ("stripe_customer_id",)}),
    )
    list_display = ("id", "username", "email", "is_staff", "stripe_customer_id")
