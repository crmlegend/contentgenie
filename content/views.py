from rest_framework.decorators import api_view, permission_classes, authentication_classes
from rest_framework.response import Response
from rest_framework import status
from billing.auth import ApiKeyAuthentication
from billing.permissions import IsSubscriber
from .serializers import GenPayload, BlogPreviewPayload
from .services import (
    norm_site, upsert_keys_for_site, get_site_keys, resolve_provider_and_model,
    clamp_temperature, ai_text, ai_blog_json, make_blog_prompt, render_preview_html
)

@api_view(["POST"])
@authentication_classes([ApiKeyAuthentication])  # API key auth (subscriber tokens)
@permission_classes([IsSubscriber])              # must be on a paid plan
def generate(request):
    s = GenPayload(data=request.data); s.is_valid(raise_exception=True)
    data = s.validated_data
    site = norm_site(data.get("site") or "")
    upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
    upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

    opts = data.get("options") or {}
    provider, model = resolve_provider_and_model(opts, site)
    temperature = clamp_temperature(opts.get("temperature") or 0.7)
    mode = (opts.get("mode") or "replacer").lower()
    prompt = data.get("prompt") or ""

    keys = get_site_keys(site)
    if provider=="openai" and not keys["openai_key"]:
        return Response({"detail":"OpenAI key missing for this site."}, status=400)
    if provider=="gemini" and not keys["gemini_key"]:
        return Response({"detail":"Gemini key missing for this site."}, status=400)

    if mode=="blog":
        reference_text = (opts.get("reference_text") or "").strip()
        sitemap_url = (opts.get("sitemap_url") or "").strip()
        composite = make_blog_prompt(prompt, reference_text, sitemap_url)
        doc = ai_blog_json(composite, model, provider, site, temperature)
        return Response(doc)
    else:
        text = ai_text(prompt, model, provider, site, temperature)
        return Response({"text": text})

@api_view(["POST"])
@authentication_classes([ApiKeyAuthentication])
@permission_classes([IsSubscriber])
def blog_preview(request):
    s = BlogPreviewPayload(data=request.data); s.is_valid(raise_exception=True)
    data = s.validated_data
    site = norm_site(data.get("site") or "")
    upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
    upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

    opts = data.get("options") or {}
    provider, model = resolve_provider_and_model(opts, site)
    temperature = clamp_temperature(opts.get("temperature") or 0.7)

    keys = get_site_keys(site)
    if provider=="openai" and not keys["openai_key"]:
        return Response({"detail":"OpenAI key missing for this site."}, status=400)
    if provider=="gemini" and not keys["gemini_key"]:
        return Response({"detail":"Gemini key missing for this site."}, status=400)

    composite = make_blog_prompt(data.get("prompt") or "", (opts.get("reference_text") or "").strip(), (opts.get("sitemap_url") or "").strip())
    doc = ai_blog_json(composite, model, provider, site, temperature)
    return Response({"html": render_preview_html(doc), "title": doc.get("title")})
