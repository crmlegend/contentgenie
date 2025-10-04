import logging

from rest_framework.decorators import api_view, permission_classes, authentication_classes
from rest_framework.response import Response
from rest_framework import status
from rest_framework.exceptions import ValidationError

from billing.auth import ApiKeyAuthentication
from billing.permissions import IsSubscriber
from .serializers import GenPayload, BlogPreviewPayload
from .services import (
    norm_site, upsert_keys_for_site, get_site_keys, resolve_provider_and_model,
    clamp_temperature, ai_text, ai_blog_json, make_blog_prompt, render_preview_html
)

logger = logging.getLogger(__name__)


def _safe_bool(v):
    return bool(v) and True or False


@api_view(["POST"])
@authentication_classes([ApiKeyAuthentication])  # API key auth (subscriber tokens)
@permission_classes([IsSubscriber])              # must be on a paid plan
def generate(request):
    """
    Generate plain text (mode='replacer') or blog JSON (mode='blog').
    Adds logging so you can see what went wrong in Log Stream / Kudu.
    """
    try:
        s = GenPayload(data=request.data)
        s.is_valid(raise_exception=True)
        data = s.validated_data

        site = norm_site(data.get("site") or "")
        # upsert keys from body + headers (we don't log the raw keys)
        upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
        upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

        opts = data.get("options") or {}
        provider, model = resolve_provider_and_model(opts, site)
        temperature = clamp_temperature(opts.get("temperature") or 0.7)
        mode = (opts.get("mode") or "replacer").lower()
        prompt = data.get("prompt") or ""

        keys = get_site_keys(site)
        logger.info(
            "generate start site=%s mode=%s provider=%s model=%s openai_key=%s gemini_key=%s",
            site, mode, provider, model, _safe_bool(keys.get("openai_key")), _safe_bool(keys.get("gemini_key"))
        )

        # preflight key checks
        if provider == "openai" and not keys["openai_key"]:
            logger.warning("generate missing OpenAI key site=%s", site)
            return Response({"detail": "OpenAI key missing for this site."}, status=400)
        if provider == "gemini" and not keys["gemini_key"]:
            logger.warning("generate missing Gemini key site=%s", site)
            return Response({"detail": "Gemini key missing for this site."}, status=400)

        if mode == "blog":
            reference_text = (opts.get("reference_text") or "").strip()
            sitemap_url = (opts.get("sitemap_url") or "").strip()
            composite = make_blog_prompt(prompt, reference_text, sitemap_url)
            doc = ai_blog_json(composite, model, provider, site, temperature)
            logger.info("generate blog ok site=%s title_len=%d sections=%d",
                        site, len(doc.get('title') or ''), len(doc.get('sections') or []))
            return Response(doc)

        # default: replacer/plain text
        text = ai_text(prompt, model, provider, site, temperature)
        logger.info("generate text ok site=%s len=%d", site, len(text or ""))
        return Response({"text": text})

    except ValidationError as e:
        # serializer validation errors
        logger.warning("generate validation error site=%s detail=%s", locals().get("site", ""), e.detail)
        raise  # let DRF produce a proper 400 response
    except Exception as e:
        logger.error("generate failed site=%s err=%s", locals().get("site", ""), str(e), exc_info=True)
        # Return a safe message so the plugin shows something useful (not a 500)
        return Response({"detail": "AI provider error. See server logs."}, status=400)


@api_view(["POST"])
@authentication_classes([ApiKeyAuthentication])
@permission_classes([IsSubscriber])
def blog_preview(request):
    """
    Generate blog preview HTML (uses the same AI path but returns rendered HTML).
    """
    try:
        s = BlogPreviewPayload(data=request.data)
        s.is_valid(raise_exception=True)
        data = s.validated_data

        site = norm_site(data.get("site") or "")
        upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
        upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

        opts = data.get("options") or {}
        provider, model = resolve_provider_and_model(opts, site)
        temperature = clamp_temperature(opts.get("temperature") or 0.7)

        keys = get_site_keys(site)
        logger.info(
            "blog_preview start site=%s provider=%s model=%s openai_key=%s gemini_key=%s",
            site, provider, model, _safe_bool(keys.get("openai_key")), _safe_bool(keys.get("gemini_key"))
        )

        if provider == "openai" and not keys["openai_key"]:
            logger.warning("blog_preview missing OpenAI key site=%s", site)
            return Response({"detail": "OpenAI key missing for this site."}, status=400)
        if provider == "gemini" and not keys["gemini_key"]:
            logger.warning("blog_preview missing Gemini key site=%s", site)
            return Response({"detail": "Gemini key missing for this site."}, status=400)

        composite = make_blog_prompt(
            data.get("prompt") or "",
            (opts.get("reference_text") or "").strip(),
            (opts.get("sitemap_url") or "").strip()
        )
        doc = ai_blog_json(composite, model, provider, site, temperature)
        html = render_preview_html(doc)
        logger.info("blog_preview ok site=%s title_len=%d html_len=%d",
                    site, len(doc.get("title") or ""), len(html or ""))
        return Response({"html": html, "title": doc.get("title")})

    except ValidationError as e:
        logger.warning("blog_preview validation error site=%s detail=%s", locals().get("site", ""), e.detail)
        raise
    except Exception as e:
        logger.error("blog_preview failed site=%s err=%s", locals().get("site", ""), str(e), exc_info=True)
        return Response({"detail": "AI provider error. See server logs."}, status=400)
