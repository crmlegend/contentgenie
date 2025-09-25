import os, json, html, datetime
from django.conf import settings
from urllib.parse import urlparse, parse_qsl, urlencode, urlunparse

TENANT_KEYS = {}
OPENAI_CLIENTS = {}
GEMINI_READY = {}

GEMINI_DEFAULT = "gemini-1.5-flash"
OPENAI_DEFAULT = "gpt-4o-mini"
ALLOWED_MODELS = {
    "gemini": {"gemini-1.5-flash","gemini-1.5-pro","gemini-1.0-pro"},
    "openai": {"gpt-4o-mini","gpt-4o","gpt-4.1-mini","gpt-4.1"},
}

def norm_site(site: str|None) -> str:
    if not site: return ""
    u = urlparse(site); host = (u.netloc or u.path).lower()
    return host[4:] if host.startswith("www.") else host

def upsert_keys_for_site(site, openai_key, gemini_key):
    s = norm_site(site)
    if not s: return
    entry = dict(TENANT_KEYS.get(s, {}))
    if openai_key: entry["openai_key"] = openai_key.strip()
    if gemini_key: entry["gemini_key"] = gemini_key.strip()
    TENANT_KEYS[s] = entry

def get_site_keys(site):
    s = norm_site(site)
    site_keys = TENANT_KEYS.get(s, {}) if s else {}
    return {
        "openai_key": site_keys.get("openai_key") or settings.ENV_OPENAI_API_KEY or "",
        "gemini_key": site_keys.get("gemini_key") or settings.ENV_GEMINI_API_KEY or "",
    }

def normalize_provider(p):
    p = (p or "").strip().lower()
    if p in ("gemini","google","googleai","gai"): return "gemini"
    if p in ("openai","chatgpt","oai","gpt"): return "openai"
    return p

def model_belongs_to(m):
    m = (m or "").strip().lower()
    if m in ALLOWED_MODELS["gemini"] or "gemini" in m: return "gemini"
    if m in ALLOWED_MODELS["openai"] or m.startswith("gpt"): return "openai"
    return None

def validate_model(provider, m):
    m = (m or "").strip()
    if provider=="gemini": return m if m in ALLOWED_MODELS["gemini"] else GEMINI_DEFAULT
    if provider=="openai": return m if m in ALLOWED_MODELS["openai"] else OPENAI_DEFAULT
    raise ValueError("unknown provider")

def resolve_provider_and_model(opts, site):
    req_p = normalize_provider((opts or {}).get("provider"))
    req_m = (opts or {}).get("model") or ""
    provider = req_p or model_belongs_to(req_m)
    if not provider:
        keys = get_site_keys(site)
        provider = "openai" if keys["openai_key"] else ("gemini" if keys["gemini_key"] else "openai")
    provider = normalize_provider(provider)
    model = validate_model(provider, req_m)
    if req_p and req_m:
        belongs = model_belongs_to(req_m)
        if belongs and belongs != provider:
            raise ValueError(f"Model '{req_m}' is not a {provider} model.")
    return provider, model

def clamp_temperature(x):
    try: t = float(x)
    except: t = 0.7
    return max(0.0, min(t, 2.0))

def get_openai_client_for(site):
    from openai import OpenAI
    api_key = get_site_keys(site)["openai_key"]
    if not api_key: raise ValueError("OpenAI key missing")
    cache_key = f"{norm_site(site) or 'GLOBAL'}::{api_key[:8]}"
    if cache_key in OPENAI_CLIENTS: return OPENAI_CLIENTS[cache_key]
    client = OpenAI(api_key=api_key); OPENAI_CLIENTS[cache_key] = client; return client

def ensure_gemini_configured_for(site):
    import google.generativeai as genai
    api_key = get_site_keys(site)["gemini_key"]
    if not api_key: raise ValueError("Gemini key missing")
    if not GEMINI_READY.get(api_key):
        genai.configure(api_key=api_key); GEMINI_READY[api_key] = True

def ai_text(prompt, model, provider, site, temperature=0.7):
    temp = clamp_temperature(temperature)
    if provider=="gemini":
        import google.generativeai as genai
        ensure_gemini_configured_for(site)
        mdl = genai.GenerativeModel(model)
        out = mdl.generate_content(prompt, generation_config={"temperature": temp})
        return (getattr(out, "text", None) or "").strip()
    client = get_openai_client_for(site)
    resp = client.chat.completions.create(
        model=model, temperature=temp,
        messages=[{"role":"system","content":"You are a helpful writing assistant."},
                  {"role":"user","content":prompt}]
    )
    return (resp.choices[0].message.content or "").strip()

def make_blog_prompt(user_prompt, reference_text="", sitemap_url=""):
    parts = [
        "Write a complete, SEO-friendly blog article with H2/H3 subheadings, short paragraphs, and bullet/numbered lists where helpful.",
        "Return ONLY a single valid JSON object with keys: title, sections[{heading,text}], faq[{q,a}].",
    ]
    if reference_text:
        parts.append("Match the tone/structure:\n---REFERENCE START---\n"+reference_text+"\n---REFERENCE END---")
    if sitemap_url:
        parts.append(f"Only create INTERNAL links under {sitemap_url}. Do not invent URLs.")
    parts.append("Topic/Prompt:\n"+(user_prompt or ""))
    return "\n\n".join(parts)

def ai_blog_json(prompt, model, provider, site, temperature=0.7):
    temp = clamp_temperature(temperature)
    if provider=="gemini":
        import google.generativeai as genai
        ensure_gemini_configured_for(site)
        mdl = genai.GenerativeModel(model)
        out = mdl.generate_content(prompt, generation_config={"temperature": temp})
        txt = (getattr(out, "text", None) or "").strip()
    else:
        client = get_openai_client_for(site)
        resp = client.chat.completions.create(
            model=model, temperature=temp, response_format={"type":"json_object"},
            messages=[{"role":"system","content":"Reply with ONLY one valid JSON object."},
                      {"role":"user","content":prompt}]
        )
        txt = (resp.choices[0].message.content or "").strip()
    try:
        data = json.loads(txt)
    except:
        data = {"title":"Draft","sections":[{"heading":"Body","text":txt}],"faq":[]}
    # normalize
    secs = data.get("sections") or []
    faq = data.get("faq") or []
    out_secs = []
    for s in secs:
        if isinstance(s, dict):
            out_secs.append({"heading": s.get("heading") or "Section", "text": s.get("text") or ""})
        else:
            out_secs.append({"heading": "Section", "text": str(s)})
    out_faq = []
    for f in faq:
        if isinstance(f, dict):
            q, a = f.get("q") or "", f.get("a") or ""
        else:
            q, a = str(f), ""
        if q or a: out_faq.append({"q": q, "a": a})
    return {"title": data.get("title") or "Draft", "sections": out_secs, "faq": out_faq}

def render_preview_html(doc):
    title = html.escape(doc.get("title") or "Draft")
    parts = [f"<div class='acr-preview'><h1 class='acr-title'>{title}</h1>"]
    for s in doc.get("sections", []):
        h = html.escape(s.get("heading") or ""); t = s.get("text") or ""
        parts.append(f"<section class='acr-sec'><h2>{h}</h2><div class='acr-body'>{t}</div></section>")
    faq = doc.get("faq", [])
    if faq:
        parts.append("<section class='acr-faq'><h2>FAQ</h2><dl>")
        for f in faq:
            q = html.escape(f.get("q") or ""); a = f.get("a") or ""
            parts.append(f"<dt>{q}</dt><dd>{a}</dd>")
        parts.append("</dl></section>")
    parts.append("</div>")
    return "".join(parts)
