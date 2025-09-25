from rest_framework import serializers
from django.contrib.auth import get_user_model
from django.contrib.auth.password_validation import validate_password
from django.db import transaction

User = get_user_model()

def user_has_username_field() -> bool:
    # Works for AbstractUser or custom models
    return any(f.name == "username" for f in User._meta.get_fields())

class RegisterSerializer(serializers.Serializer):
    email = serializers.EmailField()
    password = serializers.CharField(write_only=True, trim_whitespace=False)
    password2 = serializers.CharField(write_only=True, trim_whitespace=False)

    def validate_email(self, value):
        email = value.strip().lower()
        if User.objects.filter(email__iexact=email).exists():
            raise serializers.ValidationError("Email already registered")
        return email

    def validate(self, attrs):
        # Password confirmation
        if attrs.get("password") != attrs.get("password2"):
            raise serializers.ValidationError({"password": "Passwords do not match."})
        # Run Django’s password validators
        validate_password(attrs["password"])
        return attrs

    @transaction.atomic
    def create(self, validated_data):
        email = validated_data["email"]             # already normalized in validate_email
        password = validated_data["password"]

        if user_has_username_field():
            # Username exists → use email as username
            user = User.objects.create_user(username=email, email=email, password=password)
        else:
            # Email-only custom user (USERNAME_FIELD='email', username=None)
            user = User.objects.create_user(email=email, password=password)

        return user
