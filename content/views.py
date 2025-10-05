# import logging

# from rest_framework.decorators import api_view, permission_classes, authentication_classes
# from rest_framework.response import Response
# from rest_framework import status
# from rest_framework.exceptions import ValidationError

# from billing.auth import ApiKeyAuthentication
# from billing.permissions import IsSubscriber
# from .serializers import GenPayload, BlogPreviewPayload
# from .services import (
#     norm_site, upsert_keys_for_site, get_site_keys, resolve_provider_and_model,
#     clamp_temperature, ai_text, ai_blog_json, make_blog_prompt, render_preview_html
# )

# logger = logging.getLogger(__name__)


# def _safe_bool(v):
#     return bool(v) and True or False


# @api_view(["POST"])
# @authentication_classes([ApiKeyAuthentication])  # API key auth (subscriber tokens)
# @permission_classes([IsSubscriber])              # must be on a paid plan
# def generate(request):
#     """
#     Generate plain text (mode='replacer') or blog JSON (mode='blog').
#     Adds logging so you can see what went wrong in Log Stream / Kudu.
#     """
#     try:
#         s = GenPayload(data=request.data)
#         s.is_valid(raise_exception=True)
#         data = s.validated_data

#         site = norm_site(data.get("site") or "")
#         # upsert keys from body + headers (we don't log the raw keys)
#         upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
#         upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

#         opts = data.get("options") or {}
#         provider, model = resolve_provider_and_model(opts, site)
#         temperature = clamp_temperature(opts.get("temperature") or 0.7)
#         mode = (opts.get("mode") or "replacer").lower()
#         prompt = data.get("prompt") or ""

#         keys = get_site_keys(site)
#         logger.info(
#             "generate start site=%s mode=%s provider=%s model=%s openai_key=%s gemini_key=%s",
#             site, mode, provider, model, _safe_bool(keys.get("openai_key")), _safe_bool(keys.get("gemini_key"))
#         )

#         # preflight key checks
#         if provider == "openai" and not keys["openai_key"]:
#             logger.warning("generate missing OpenAI key site=%s", site)
#             return Response({"detail": "OpenAI key missing for this site."}, status=400)
#         if provider == "gemini" and not keys["gemini_key"]:
#             logger.warning("generate missing Gemini key site=%s", site)
#             return Response({"detail": "Gemini key missing for this site."}, status=400)

#         if mode == "blog":
#             reference_text = (opts.get("reference_text") or "").strip()
#             sitemap_url = (opts.get("sitemap_url") or "").strip()
#             composite = make_blog_prompt(prompt, reference_text, sitemap_url)
#             doc = ai_blog_json(composite, model, provider, site, temperature)
#             logger.info("generate blog ok site=%s title_len=%d sections=%d",
#                         site, len(doc.get('title') or ''), len(doc.get('sections') or []))
#             return Response(doc)

#         # default: replacer/plain text
#         text = ai_text(prompt, model, provider, site, temperature)
#         logger.info("generate text ok site=%s len=%d", site, len(text or ""))
#         return Response({"text": text})

#     except ValidationError as e:
#         # serializer validation errors
#         logger.warning("generate validation error site=%s detail=%s", locals().get("site", ""), e.detail)
#         raise  # let DRF produce a proper 400 response
#     except Exception as e:
#         logger.error("generate failed site=%s err=%s", locals().get("site", ""), str(e), exc_info=True)
#         # Return a safe message so the plugin shows something useful (not a 500)
#         return Response({"detail": "AI provider error. See server logs."}, status=400)


# @api_view(["POST"])
# @authentication_classes([ApiKeyAuthentication])
# @permission_classes([IsSubscriber])
# def blog_preview(request):
#     """
#     Generate blog preview HTML (uses the same AI path but returns rendered HTML).
#     """
#     try:
#         s = BlogPreviewPayload(data=request.data)
#         s.is_valid(raise_exception=True)
#         data = s.validated_data

#         site = norm_site(data.get("site") or "")
#         upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
#         upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

#         opts = data.get("options") or {}
#         provider, model = resolve_provider_and_model(opts, site)
#         temperature = clamp_temperature(opts.get("temperature") or 0.7)

#         keys = get_site_keys(site)
#         logger.info(
#             "blog_preview start site=%s provider=%s model=%s openai_key=%s gemini_key=%s",
#             site, provider, model, _safe_bool(keys.get("openai_key")), _safe_bool(keys.get("gemini_key"))
#         )

#         if provider == "openai" and not keys["openai_key"]:
#             logger.warning("blog_preview missing OpenAI key site=%s", site)
#             return Response({"detail": "OpenAI key missing for this site."}, status=400)
#         if provider == "gemini" and not keys["gemini_key"]:
#             logger.warning("blog_preview missing Gemini key site=%s", site)
#             return Response({"detail": "Gemini key missing for this site."}, status=400)

#         composite = make_blog_prompt(
#             data.get("prompt") or "",
#             (opts.get("reference_text") or "").strip(),
#             (opts.get("sitemap_url") or "").strip()
#         )
#         doc = ai_blog_json(composite, model, provider, site, temperature)
#         html = render_preview_html(doc)
#         logger.info("blog_preview ok site=%s title_len=%d html_len=%d",
#                     site, len(doc.get("title") or ""), len(html or ""))
#         return Response({"html": html, "title": doc.get("title")})

#     except ValidationError as e:
#         logger.warning("blog_preview validation error site=%s detail=%s", locals().get("site", ""), e.detail)
#         raise
#     except Exception as e:
#         logger.error("blog_preview failed site=%s err=%s", locals().get("site", ""), str(e), exc_info=True)
#         return Response({"detail": "AI provider error. See server logs."}, status=400)





import logging, time, uuid

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
from rest_framework.decorators import api_view, permission_classes, authentication_classes
from rest_framework.response import Response
from rest_framework.exceptions import ValidationError
from rest_framework import status
import logging, re, time
from billing.auth import ApiKeyAuthentication
from billing.permissions import IsSubscriber
from .serializers import GenPayload, BlogPreviewPayload
from .services import (
    norm_site, upsert_keys_for_site, get_site_keys, resolve_provider_and_model,
    clamp_temperature, ai_text, ai_blog_json, make_blog_prompt, render_preview_html
)


logger = logging.getLogger(__name__)

def _safe_bool(v):  # show True/False only
    return bool(v) and True or False

def _cid(request):
    """Correlation id to trace a single request through logs."""
    return request.headers.get("X-Request-ID") or str(uuid.uuid4())

def _safe_opts(opts: dict):
    """Never log prompt/reference text; show only lengths + model-ish toggles."""
    if not isinstance(opts, dict): return {}
    out = {}
    for k, v in opts.items():
        if k in {"prompt", "reference_text", "sitemap_url"}:
            # just show length to prove data arrived
            out[k + "_len"] = len((v or "").strip())
        elif k == "temperature":
            out[k] = v
        elif k == "mode":
            out[k] = v
        else:
            # short/primitive values are safe; avoid dumping nested dicts
            out[k] = v if isinstance(v, (str, int, float, bool)) else type(v).__name__
    return out

# @api_view(["POST"])
# @authentication_classes([ApiKeyAuthentication])  # API key auth (subscriber tokens)
# @permission_classes([IsSubscriber])              # must be on a paid plan
# def generate(request):
#     """
#     Generate plain text (mode='replacer') or blog JSON (mode='blog').
#     Logs include: cid, site, mode, provider, model, key presence, option sizes, elapsed time.
#     """
    
#     print("Request receieved successfully ")
#     cid = _cid(request)
#     t0 = time.time()
#     try:
#         s = GenPayload(data=request.data); s.is_valid(raise_exception=True)
#         data = s.validated_data

#         site = norm_site(data.get("site") or "")
#         # upsert keys from body + headers (we don't log raw keys)
#         upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
#         upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

#         opts = data.get("options") or {}
#         provider, model = resolve_provider_and_model(opts, site)
#         temperature = clamp_temperature(opts.get("temperature") or 0.7)
#         mode = (opts.get("mode") or "replacer").lower()
#         prompt = data.get("prompt") or ""

#         keys = get_site_keys(site)

#         logger.info(
#             "gen: start cid=%s site=%s mode=%s provider=%s model=%s keys(openai=%s,gemini=%s) opts=%s",
#             cid, site, mode, provider, model, _safe_bool(keys.get("openai_key")), _safe_bool(keys.get("gemini_key")),
#             _safe_opts(opts)
#         )

#         # preflight key checks
#         if provider == "openai" and not keys["openai_key"]:
#             logger.warning("gen: missing_openai_key cid=%s site=%s", cid, site)
#             return Response({"detail": "OpenAI key missing for this site."}, status=400)
#         if provider == "gemini" and not keys["gemini_key"]:
#             logger.warning("gen: missing_gemini_key cid=%s site=%s", cid, site)
#             return Response({"detail": "Gemini key missing for this site."}, status=400)

#         # AI call (time it: useful for 502/timeouts)
#         t1 = time.time()
#         if mode == "blog":
#             reference_text = (opts.get("reference_text") or "").strip()
#             sitemap_url = (opts.get("sitemap_url") or "").strip()
#             composite = make_blog_prompt(prompt, reference_text, sitemap_url)

#             doc = ai_blog_json(composite, model, provider, site, temperature)
#             elapsed = time.time() - t1
#             logger.info(
#                 "gen: blog_ok cid=%s site=%s elapsed=%.2fs title_len=%d sections=%d",
#                 cid, site, elapsed, len(doc.get('title') or ''), len(doc.get('sections') or [])
#             )
#             logger.info("gen: done cid=%s total=%.2fs", cid, time.time() - t0)
#             return Response(doc)

#         text = ai_text(prompt, model, provider, site, temperature)
#         elapsed = time.time() - t1
#         logger.info("gen: text_ok cid=%s site=%s elapsed=%.2fs len=%d", cid, site, elapsed, len(text or ""))
#         logger.info("gen: done cid=%s total=%.2fs", cid, time.time() - t0)
#         return Response({"text": text})

#     except ValidationError as e:
#         # serializer validation errors (show field names, not full data)
#         logger.warning("gen: validation cid=%s site=%s detail=%s", cid, locals().get("site", ""), e.detail)
#         raise  # DRF will emit 400 with details
#     except Exception as e:
#         # full traceback recorded; client sees safe message
#         logger.error("gen: failed cid=%s site=%s err=%s", cid, locals().get("site", ""), str(e), exc_info=True)
#         return Response({"detail": "AI provider error. See server logs."}, status=400)







logger = logging.getLogger(__name__)

def _safe_bool(x): return bool(x)
def _safe_opts(opts):
    try:
        # Don’t dump whole payload; show only small summary
        keys = list((opts or {}).keys())
        return {"keys": keys[:10], "len": len(keys)}
    except Exception:
        return {}

def _cid(request):
    # Try to tag logs with a per-request id (from header or create quickly)
    return request.headers.get("X-Request-Id") or f"cid-{int(time.time()*1000)}"

@api_view(["POST"])
@authentication_classes([ApiKeyAuthentication])  # API key auth (subscriber tokens)
@permission_classes([IsSubscriber])              # must be on a paid plan
def generate(request):
    """
    If 'elementor' is present in body:
        - Traverse Elementor JSON like the first tab (pages/posts)
        - Rewrite allowed fields with AI
        - Return {"elementor": [...]}

    Else:
        - Behave like before: 'mode' = 'replacer' => {"text": "..."} ; 'mode'='blog' => blog JSON
    """
    cid = _cid(request)
    t0 = time.time()
    try:
        s = GenPayload(data=request.data)
        s.is_valid(raise_exception=True)
        data = s.validated_data

        site = norm_site(data.get("site") or "")
        # upsert keys from body + headers (we don't log raw keys)
        upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
        upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

        opts = data.get("options") or {}
        provider, model = resolve_provider_and_model(opts, site)
        temperature = clamp_temperature(opts.get("temperature") or 0.7)
        mode = (opts.get("mode") or "replacer").lower()
        prompt = data.get("prompt") or ""

        keys = get_site_keys(site)
        logger.info(
            "gen: start cid=%s site=%s mode=%s provider=%s model=%s keys(openai=%s,gemini=%s) opts=%s",
            cid, site, mode, provider, model, _safe_bool(keys.get("openai_key")), _safe_bool(keys.get("gemini_key")),
            _safe_opts(opts)
        )

        # preflight key checks
        if provider == "openai" and not keys["openai_key"]:
            logger.warning("gen: missing_openai_key cid=%s site=%s", cid, site)
            return Response({"detail": "OpenAI key missing for this site."}, status=400)
        if provider == "gemini" and not keys["gemini_key"]:
            logger.warning("gen: missing_gemini_key cid=%s site=%s", cid, site)
            return Response({"detail": "Gemini key missing for this site."}, status=400)

        # ===== Elementor rewrite path (first tab) =====
        elementor = data.get("elementor")
        if isinstance(elementor, list):
            t1 = time.time()

            # --- Allowed map (mirrors your PHP rules) ---
            ALLOWED = {
                "heading": [
                    {"key": "title", "html": False, "shape": "string", "purpose": "headline"},
                ],
                "text-editor": [
                    {"key": "editor", "html": True, "shape": "string", "purpose": "html"},
                ],
                "button": [
                    {"key": "text", "html": False, "shape": "string", "purpose": "label"},
                ],
                "icon-box": [
                    {"key": "title_text", "html": False, "shape": "string", "purpose": "headline"},
                    {"key": "description_text", "html": True, "shape": "string", "purpose": "paragraph"},
                ],
                "image-box": [
                    {"key": "title_text", "html": False, "shape": "string", "purpose": "headline"},
                    {"key": "description_text", "html": True, "shape": "string", "purpose": "paragraph"},
                ],
                "testimonial": [
                    {"key": "testimonial_content", "html": True, "shape": "string", "purpose": "paragraph"},
                    {"key": "testimonial_name", "html": False, "shape": "string", "purpose": "label"},
                    {"key": "testimonial_job", "html": False, "shape": "string", "purpose": "label"},
                ],
                "alert": [
                    {"key": "alert_title", "html": False, "shape": "string", "purpose": "headline"},
                    {"key": "alert_description", "html": True, "shape": "string", "purpose": "paragraph"},
                ],
                "html": [
                    {"key": "html", "html": True, "shape": "string", "purpose": "html"},
                ],
                # Repeaters
                "accordion": [
                    {"key": "tabs[].tab_title", "html": False, "shape": "string_or_raw", "purpose": "headline"},
                    {"key": "tabs[].tab_content", "html": True, "shape": "string", "purpose": "html"},
                ],
                # NEW: nested-accordion & icon-list (as in your PHP)
                "nested-accordion": [
                    {"key": "items[].item_title", "html": False, "shape": "string_or_raw", "purpose": "headline"},
                ],
                "icon-list": [
                    {"key": "icon_list[].text", "html": False, "shape": "string_or_raw", "purpose": "label"},
                ],
            }

            # Helpers to preserve Elementor ["raw"] shape
            def _get_raw_or_string(val):
                if isinstance(val, dict):
                    return str(val.get("raw") or "")
                return str(val)

            def _put_back_raw_or_string(settings, key, original_value, new_value):
                if isinstance(original_value, dict):
                    if key not in settings or not isinstance(settings[key], dict):
                        settings[key] = {}
                    settings[key]["raw"] = new_value
                else:
                    settings[key] = new_value

            repeater_re = re.compile(r"^([a-z0-9_]+)\[\]\.([a-z0-9_]+)$", re.I)

            # Build an AI prompt per field (same spirit as your PHP)
            def build_prompt(widget_type, field_key, original, is_html):
                block = "HTML" if is_html else "TEXT"
                instructions = str(prompt or "")
                return (
                    f"Rewrite the following {block} according to these instructions:\n"
                    f"Instructions: {instructions}\n\n\n"
                    f"BEGIN_ORIGINAL_{block}\n\n\n{original}\n\n\nEND_ORIGINAL_{block}\n"
                    f"Return ONLY the rewritten {block} in the same format as the original and do not include any additional text."
                )

            # Recursive traversal like Elementor structure: list[ element{ elType, widgetType, settings, elements } ]
            def traverse(elements):
                if not isinstance(elements, list):
                    return elements
                out = []
                for el in elements:
                    if not isinstance(el, dict):
                        out.append(el); continue

                    # Process widgets
                    if el.get("elType") == "widget" and isinstance(el.get("settings"), dict):
                        widget_type = el.get("widgetType") or ""
                        settings = dict(el["settings"])  # copy

                        rules = ALLOWED.get(widget_type) or []
                        for rule in rules:
                            key = rule.get("key")
                            is_html = bool(rule.get("html"))
                            shape = rule.get("shape") or "string"

                            # Repeater pattern: e.g. tabs[].tab_title
                            m = repeater_re.match(key or "")
                            if m:
                                rep_key, item_key = m.group(1), m.group(2)
                                rep_list = settings.get(rep_key)
                                if isinstance(rep_list, list):
                                    for idx in range(len(rep_list)):
                                        item = rep_list[idx]
                                        if not isinstance(item, dict) or item_key not in item:
                                            continue
                                        orig_val = item[item_key]
                                        current = _get_raw_or_string(orig_val) if shape == "string_or_raw" else str(orig_val or "")
                                        if not current:
                                            continue

                                        # AI call
                                        try:
                                            ptxt = build_prompt(widget_type, key, current, is_html)
                                            rewritten = ai_text(ptxt, model, provider, site, temperature)
                                        except Exception:
                                            logger.exception("ai_text failed (repeater) cid=%s site=%s widget=%s key=%s", cid, site, widget_type, key)
                                            raise

                                        # Put back preserving shape when needed
                                        if shape == "string_or_raw":
                                            if isinstance(orig_val, dict):
                                                if not isinstance(item.get(item_key), dict):
                                                    item[item_key] = {}
                                                item[item_key]["raw"] = rewritten
                                            else:
                                                item[item_key] = rewritten
                                        else:
                                            item[item_key] = rewritten
                                continue

                            # Flat field
                            if key in settings:
                                orig_val = settings[key]
                                current = _get_raw_or_string(orig_val) if shape == "string_or_raw" else str(orig_val or "")
                                if current:
                                    try:
                                        ptxt = build_prompt(widget_type, key, current, is_html)
                                        rewritten = ai_text(ptxt, model, provider, site, temperature)
                                    except Exception:
                                        logger.exception("ai_text failed (flat) cid=%s site=%s widget=%s key=%s", cid, site, widget_type, key)
                                        raise

                                    if shape == "string_or_raw":
                                        _put_back_raw_or_string(settings, key, orig_val, rewritten)
                                    else:
                                        settings[key] = rewritten

                        # write back settings
                        el["settings"] = settings

                    # Recurse into children
                    if isinstance(el.get("elements"), list):
                        el["elements"] = traverse(el["elements"])

                    out.append(el)
                return out

            # Do the traversal => many small AI calls (one per field)
            try:
                updated = traverse(elementor)
            except Exception as e:
                logger.error("gen: traverse failed cid=%s site=%s err=%s", cid, site, str(e), exc_info=True)
                return Response({"detail": "AI processing failed while rewriting Elementor content."}, status=400)

            elapsed = time.time() - t1
            logger.info("gen: elementor_ok cid=%s site=%s elapsed=%.2fs", cid, site, elapsed)
            logger.info("gen: done cid=%s total=%.2fs", cid, time.time() - t0)
            return Response({"elementor": updated})

        # ===== Original non-Elementor path (kept exactly as before) =====
        t1 = time.time()
        if mode == "blog":
            reference_text = (opts.get("reference_text") or "").strip()
            sitemap_url = (opts.get("sitemap_url") or "").strip()
            composite = make_blog_prompt(prompt, reference_text, sitemap_url)

            doc = ai_blog_json(composite, model, provider, site, temperature)
            elapsed = time.time() - t1
            logger.info(
                "gen: blog_ok cid=%s site=%s elapsed=%.2fs title_len=%d sections=%d",
                cid, site, elapsed, len(doc.get('title') or ''), len(doc.get('sections') or [])
            )
            logger.info("gen: done cid=%s total=%.2fs", cid, time.time() - t0)
            return Response(doc)

        text = ai_text(prompt, model, provider, site, temperature)
        elapsed = time.time() - t1
        logger.info("gen: text_ok cid=%s site=%s elapsed=%.2fs len=%d", cid, site, elapsed, len(text or ""))
        logger.info("gen: done cid=%s total=%.2fs", cid, time.time() - t0)
        return Response({"text": text})

    except ValidationError as e:
        logger.warning("gen: validation cid=%s site=%s detail=%s", cid, locals().get("site", ""), e.detail)
        raise  # DRF will emit a 400

    except Exception as e:
        logger.error("gen: failed cid=%s site=%s err=%s", cid, locals().get("site", ""), str(e), exc_info=True)
        return Response({"detail": "AI provider error. See server logs."}, status=400)























@api_view(["POST"])
@authentication_classes([ApiKeyAuthentication])
@permission_classes([IsSubscriber])
def blog_preview(request):
    """
    Generate blog preview HTML (same AI path but returns rendered HTML).
    """
    print("request receieved successfully ")
    cid = _cid(request)
    t0 = time.time()
    try:
        s = BlogPreviewPayload(data=request.data); s.is_valid(raise_exception=True)
        data = s.validated_data

        site = norm_site(data.get("site") or "")
        upsert_keys_for_site(site, data.get("openai_key"), data.get("gemini_key"))
        upsert_keys_for_site(site, request.headers.get("X-Openai-Key"), request.headers.get("X-Gemini-Key"))

        opts = data.get("options") or {}
        provider, model = resolve_provider_and_model(opts, site)
        temperature = clamp_temperature(opts.get("temperature") or 0.7)

        keys = get_site_keys(site)
        logger.info(
            "bp: start cid=%s site=%s provider=%s model=%s keys(openai=%s,gemini=%s) opts=%s",
            cid, site, provider, model, _safe_bool(keys.get("openai_key")), _safe_bool(keys.get("gemini_key")),
            _safe_opts(opts)
        )

        if provider == "openai" and not keys["openai_key"]:
            logger.warning("bp: missing_openai_key cid=%s site=%s", cid, site)
            return Response({"detail": "OpenAI key missing for this site."}, status=400)
        if provider == "gemini" and not keys["gemini_key"]:
            logger.warning("bp: missing_gemini_key cid=%s site=%s", cid, site)
            return Response({"detail": "Gemini key missing for this site."}, status=400)

        t1 = time.time()
        composite = make_blog_prompt(
            data.get("prompt") or "",
            (opts.get("reference_text") or "").strip(),
            (opts.get("sitemap_url") or "").strip()
        )
        doc = ai_blog_json(composite, model, provider, site, temperature)
        html = render_preview_html(doc)
        elapsed = time.time() - t1

        logger.info("bp: ok cid=%s site=%s elapsed=%.2fs title_len=%d html_len=%d",
                    cid, site, elapsed, len(doc.get("title") or ""), len(html or ""))
        logger.info("bp: done cid=%s total=%.2fs", cid, time.time() - t0)
        return Response({"html": html, "title": doc.get("title")})

    except ValidationError as e:
        logger.warning("bp: validation cid=%s site=%s detail=%s", cid, locals().get("site", ""), e.detail)
        raise
    except Exception as e:
        logger.error("bp: failed cid=%s site=%s err=%s", cid, locals().get("site", ""), str(e), exc_info=True)
        return Response({"detail": "AI provider error. See server logs."}, status=400)
