from django import forms
from django.contrib.auth import get_user_model
from django.contrib.auth.forms import UserCreationForm, AuthenticationForm
from django.contrib.auth.forms import PasswordChangeForm

User = get_user_model()

INPUT = (
    "block w-full rounded-xl border border-gray-300 bg-white text-gray-900 "
    "placeholder-gray-400 px-3 py-2.5 "
    "focus:outline-none focus:ring-2 focus:ring-brand-600 focus:border-brand-600"
)

class SignUpForm(UserCreationForm):
    email = forms.EmailField(required=False)

    class Meta(UserCreationForm.Meta):
        model = User
        fields = ("username", "email", "password1", "password2")

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)

        # Tidy default Django help text (make quiet)
        self.fields["username"].help_text = "3–150 chars. Letters, digits, @/./+/-/_"
        self.fields["password1"].help_text = None   # we’ll show our own short tips
        self.fields["password2"].help_text = "Enter the same password again."

        # Style + placeholders
        self.fields["username"].widget.attrs.update({
            "class": INPUT, "placeholder": "e.g. rafay", "autocomplete": "username"
        })
        self.fields["email"].widget.attrs.update({
            "class": INPUT, "placeholder": "you@example.com", "autocomplete": "email"
        })
        self.fields["password1"].widget.attrs.update({
            "class": INPUT + " pr-14", "placeholder": "Create a password",
            "autocomplete": "new-password", "id": "id_password1"
        })
        self.fields["password2"].widget.attrs.update({
            "class": INPUT + " pr-14", "placeholder": "Confirm password",
            "autocomplete": "new-password", "id": "id_password2"
        })




class NiceLoginForm(AuthenticationForm):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["username"].widget.attrs.update({
            "class": INPUT, "placeholder": "Username", "autocomplete": "username"
        })
        self.fields["password"].widget.attrs.update({
            "class": INPUT + " pr-14", "placeholder": "Password",
            "autocomplete": "current-password", "id": "id_login_password"
        })






class ProfileForm(forms.ModelForm):
    class Meta:
        model = User
        fields = ["username"]     # email often read-only in SaaS
        widgets = {
            "username": forms.TextInput(attrs={
                "class": "block w-full rounded-xl border-gray-300 focus:border-indigo-600 focus:ring-indigo-600",
                "placeholder": "Username",
            }),
        }

class DashboardPasswordChangeForm(PasswordChangeForm):
    # style the default PasswordChangeForm fields
    old_password = forms.CharField(
        widget=forms.PasswordInput(attrs={"class":"block w-full rounded-xl border-gray-300 focus:border-indigo-600 focus:ring-indigo-600"})
    )
    new_password1 = forms.CharField(
        widget=forms.PasswordInput(attrs={"class":"block w-full rounded-xl border-gray-300 focus:border-indigo-600 focus:ring-indigo-600"})
    )
    new_password2 = forms.CharField(
        widget=forms.PasswordInput(attrs={"class":"block w-full rounded-xl border-gray-300 focus:border-indigo-600 focus:ring-indigo-600"})
    )
    
    
    
    
    
    
    
    
 
 
 