# core/celery.py
import os
from celery import Celery

# Tell Celery which Django settings to use
os.environ.setdefault("DJANGO_SETTINGS_MODULE", "core.settings")

# Create a Celery app named "core"
app = Celery("core")

# Read settings from Django settings.py, all CELERY_ variables
app.config_from_object("django.conf:settings", namespace="CELERY")

# Automatically discover tasks in all installed apps
app.autodiscover_tasks()


