from django.contrib.auth.models import AbstractUser
from django.db import models

class User(AbstractUser):
    email = models.EmailField(blank=True, null=True)
    stripe_customer_id = models.CharField(max_length=128, null=True, blank=True)

    REQUIRED_FIELDS = []  # keep default field set happy
