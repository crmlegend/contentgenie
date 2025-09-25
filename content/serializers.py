from rest_framework import serializers

class GenPayload(serializers.Serializer):
    prompt = serializers.CharField(required=False, allow_blank=True)
    options = serializers.DictField(required=False)
    site = serializers.CharField(required=False, allow_blank=True)
    openai_key = serializers.CharField(required=False, allow_blank=True)
    gemini_key = serializers.CharField(required=False, allow_blank=True)

class BlogPreviewPayload(GenPayload):
    pass




