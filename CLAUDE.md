# Claude Code — Pixelfed Fork

## Repository-Kontext

Dies ist ein Fork von [`pixelfed/pixelfed`](https://github.com/pixelfed/pixelfed), der unsere
Bugfixes und Anpassungen als Git-Commits pflegt, damit Upstream-Upgrades per `git rebase`
möglich sind statt manuellem Datei-Diff.

**Remotes:**
- `origin` → `https://github.com/cryptomentor-de/pixelfed` (unser Fork)
- `upstream` → `https://github.com/pixelfed/pixelfed` (Upstream)

**Aktiver Patch-Branch:** `patches/v0.12.7` (Commits auf Upstream-Tag `v0.12.7`)

**Build & Deployment:**
- Push auf `patches/*` → `.github/workflows/build-patch.yaml` startet automatisch
- Kaniko baut das Image mit `docker/Dockerfile` (Build-Context = dieses Repo-Root)
- Nach erfolgreichem Build: `apps/pixelfed/03-pixelfed.yaml` im k8s-Repo wird per `K8S_REPO_PAT` aktualisiert
- ArgoCD synct den neuen Image-Tag automatisch → Deployment

**Required Secrets** (in `cryptomentor-de/pixelfed` → Settings → Secrets → Actions):
- `SCW_SECRET_KEY` — Scaleway Registry Push
- `K8S_REPO_PAT` — Manifest-Update + Fork-Clone im kaniko-Job

---

## Gepflegte Patches (patches/v0.12.7)

| Commit | Datei | Fix |
|--------|-------|-----|
| #6609 | `app/Exceptions/Handler.php` | ValidationException → HTTP 422 statt 500 |
| #6610 | `app/Http/Controllers/Api/ApiV1Controller.php` | `nullable` statt `sometimes` für `max_id`/`min_id` |
| #6612 | `app/Util/Media/Image.php` | EXIF `orient()` vor Dimensions (Portrait → Landscape Fix) |
| RESTRICTED | `app/Http/Kernel.php` + `app/Http/Middleware/RestrictedAccess.php` | API-Token-Auth in Middleware |
| #6617 | `app/Jobs/VideoPipeline/VideoThumbnail.php` | S3-Upload, faststart, video/quicktime |
| #6617 | `app/Jobs/VideoPipeline/VideoThumbnailToCloudPipeline.php` | Backfill von S3 lesen, lokales Thumbnail wiederverwenden |
| #6617 | `app/Jobs/VideoPipeline/VideoHlsPipeline.php` | Video von S3 lesen statt lokal |

**Statische Assets** (`default.jpg`, `default.png`, `no-preview.png`) liegen in `docker/`
dieses Repos — Binärdateien, kein Merge-Bedarf beim Rebase.

---

## Upgrade-Prozess bei neuem Upstream-Release

```bash
# Upstream-Tags holen
git fetch upstream

# Patch-Branch auf neuen Tag rebasen
git checkout patches/v0.12.7
git rebase v0.12.8   # Git zeigt Konflikte wo nötig → lösen

# Neuen Branch pushen
git push origin patches/v0.12.8
# → Workflow erkennt: MANIFEST_BASE=v0.12.7 ≠ v0.12.8 → skip
# → Ersten Build manuell starten: workflow_dispatch mit base_tag=v0.12.8 patch_tag=v0.12.8-p1
# → Danach: Auto-Trigger bei jedem weiteren Push auf patches/v0.12.8
```

**Lokaler Smoke-Test (vom Repo-Root):**
```bash
docker build --build-arg BASE_TAG=v0.12.7 -f docker/Dockerfile .
```

**Checkliste beim Rebase:**
- [ ] Release Notes prüfen: Sind unsere Issues (#6609–#6617) upstream gefixt? → Patch entfernen
- [ ] Konflikte in `ApiV1Controller.php` besonders prüfen (riesige Datei, aktiv entwickelt)
- [ ] `VideoThumbnail.php` prüfen (komplex, Video-Pipeline ändert sich)
- [ ] `Kernel.php` / `RestrictedAccess.php` prüfen (Middleware-Chain kann sich ändern)
- [ ] `docker/Dockerfile` — `ARG BASE_TAG` auf neuen Upstream-Tag aktualisieren

---

## Pixelfed-Eigenheiten — Technisches Wissen

### config_cache — DB überschreibt Env-Vars

Pixelfed hat eine eigene `config_cache`-Tabelle in MySQL, die alle `config()`-Werte
überschreibt. Env-Vars und `ConfigCacheService`-Aufrufe aus dem Admin-Panel gelten erst
nach Redis-Cache-Invalidierung (TTL 30 Min).

**Betrifft u.a.:** `pixelfed.media_types`, `pixelfed.max_photo_size`, `pixelfed.cloud_storage`

Wert lesen/setzen via tinker:
```php
// Lesen
config_cache('pixelfed.media_types');

// Setzen
app(\App\Services\ConfigCacheService::class)->put('pixelfed.media_types', 'image/jpeg,...');

// Redis-Cache leeren danach
\Illuminate\Support\Facades\Cache::forget('api:v2:instance-data-response-v2');
\Illuminate\Support\Facades\Cache::forget('api:v1:instance-data-response');
```

### Media-Limits — Dead Code

Diese Env-Vars werden vom PHP-Code **nicht ausgewertet:**
- `MAX_VIDEO_SIZE` → kein Config-Key in `config/pixelfed.php`
- `MAX_VIDEO_LENGTH` → kein Config-Key in `config/pixelfed.php`
- `PF_OPTIMIZE_VIDEOS` → `VideoOptimize::transcode()` beginnt mit `return;`, komplett toter Code

Das **tatsächliche Upload-Limit** für alle Medien (Fotos und Videos) kommt aus `MAX_PHOTO_SIZE`
(in KB) und dem entsprechenden `config_cache`-Wert `pixelfed.max_photo_size`.

### Video-Verarbeitung

**Pipeline:** Upload → `VideoThumbnail` (Job, Queue `mmo`) → `VideoThumbnailToCloudPipeline` + `VideoHlsPipeline`

- `VideoOptimize` ist toter Code (`return;` am Anfang von `transcode()`)
- HLS (`VideoHlsPipeline`): transcodiert MP4 → HLS (.m3u8 + 16s .ts-Segmente, 1000 kbps X264)
- HLS ist standardmäßig deaktiviert (`config('media.hls.enabled')` = false)
- Ohne HLS zeigt der Pixelfed-Webplayer "no preview" für alle Videos — erwartet, kein Bug

**iOS-Kompatibilität:**
- iOS sendet `video/quicktime` als MIME-Type — unser Patch akzeptiert und remuxed MOV → MP4
- HEVC 10-bit (yuv420p10le): kein Android-Hardware-Decoder → Software-Decode schlägt fehl
- moov-Atom muss vorne sein (faststart) für iOS Progressive Streaming → unser Patch erledigt das

**Queue-Worker-Speicher:** `--memory=1536` (PHP Graceful Restart bei 1,5 GB),
K8s-Limit 2 Gi. Ohne ausreichend RAM: OOMKill bei HEVC-Videos.

### MIME-Typ-Validierung

```php
$mimes = explode(',', config_cache('pixelfed.media_types'));
if (in_array($photo->getMimeType(), $mimes) == false) {
    abort(403, 'Invalid or unsupported mime type.');
}
```

Erlaubte Typen (aktuell in config_cache):
`image/jpeg, image/png, image/gif, image/webp, image/avif, image/heic, video/mp4, video/mov, video/quicktime`

### RESTRICTED_INSTANCE — Middleware-Logik

`RestrictedAccess.php` prüft **beide** Guards:
```php
if (! Auth::guard($guard)->check() && ! Auth::guard('api')->check()) { ... }
```
Ohne den `Auth::guard('api')`-Check würden mobile App-Nutzer mit gültigem OAuth-Token
auf `/login` umgeleitet, wenn sie `api/pixelfed/v1/*`-Routen treffen (web-Gruppe).

Whitelist (öffentlich ohne Login): Login-Seiten, Einzel-Posts (`p/*/*`), Collections (`c/*/*`),
`oauth/token`. KEINE Listing-Endpunkte.

### Technisches Umfeld

- **PHP:** 8.4 (aus docker-php-ext-install exif Build-Output)
- **Framework:** Laravel (Queue, Jobs, Middleware, Passport für OAuth)
- **Datenbank:** MySQL + Redis (Session, Queue, Cache)
- **Storage:** S3 (Scaleway) via `DANGEROUSLY_SET_FILESYSTEM_DRIVER=s3`
- **Queue:** Redis-basiert, Queue-Namen: `high, default, mmo, ...`

---

## Kubernetes-Kontext

**Namespace:** `pixelfed`  
**Deployment:** `pixelfed` — 3 Container: `pixelfed`, `queue-worker`, `scheduler`  
**Service:** `pixelfed` (Port 80 → 8080)  
**Kubeconfig:** `~/.kube/config-ops` (Standard, eingeschränkte Rechte: kein `exec`, kein `get secrets`)  
**Admin** (z.B. für `php artisan tinker`): `KUBECONFIG=~/.kube/config` — explizite Freigabe nötig

Sichere Diagnose-Befehle (keine Secrets):
```bash
kubectl get pods -n pixelfed
kubectl logs deployment/pixelfed -n pixelfed -c pixelfed --tail=50
kubectl logs deployment/pixelfed -n pixelfed -c queue-worker --tail=50
kubectl get events -n pixelfed --sort-by='.lastTimestamp'
```

---

## Arbeitsweise

- **Konzept vor Implementierung:** Vor jeder Änderung zuerst ein Konzept vorstellen und auf
  explizite Freigabe warten. Ausnahme: Der User fordert explizit eine direkte Umsetzung
  (z.B. "bitte umsetzen", "mach das", "ja").
- **Fragen → nur analysieren, nie direkt umsetzen.** Wenn der User fragt ("warum...", "was ist
  hier falsch"), nur analysieren und erklären — nie ohne Freigabe patchen, committen oder pushen.
- **Jede git-Aktion braucht eine eigene explizite Freigabe** — Umsetzen, Committen und Pushen
  sind drei separate Schritte:
  - "Soll ich das umsetzen?" → nur Code-Änderung, kein Commit, kein Push
  - "Soll ich committen?" → nur Commit, kein Push
  - "Soll ich pushen?" → nur Push
  - Eine frühere Freigabe gilt nicht für nachfolgende Aktionen.
  - **NIEMALS Commit und Push in einem Schritt kombinieren.**
  - **KEIN Push ohne explizites "push" oder "ja" als Antwort auf eine konkrete Push-Frage.**

---

## Allgemeine Entwicklungsregeln

- **Keine unnötigen Kommentare.** Nur wenn das WARUM nicht offensichtlich ist: versteckte
  Constraint, subtile Invariante, Workaround für einen spezifischen Bug. Kein Kommentar der
  erklärt WAS der Code tut — das tun gut benannte Identifier.
- **Nichts über den Auftrag hinaus bauen.** Kein Refactoring, keine Abstraktion, keine Features
  die nicht explizit gefordert sind. Drei ähnliche Zeilen sind besser als eine verfrühte
  Abstraktion.
- **Kein Error-Handling für unmögliche Szenarien.** Nur an Systemgrenzen validieren
  (User-Input, externe APIs). Framework-Garantien vertrauen.
- **Keine halbfertigen Implementierungen.** Entweder vollständig umsetzen oder klar
  kommunizieren was fehlt.
- **Keine Backwards-Compatibility-Hacks** (unused vars, re-exports, entfernte Kommentare).
  Wenn etwas sicher unused ist: vollständig löschen.
- **Sicherheit:** Keine Command Injection, XSS, SQL Injection oder andere OWASP-Top-10-
  Schwachstellen einführen. Wenn unsicherer Code entdeckt wird: sofort korrigieren.

---

## Security

- Keine Standardpasswörter — auch nicht temporär, auch nicht in Kommentaren.
- Keine Secrets in Dateien die committet werden. `.env`-Dateien niemals committen.
- Bei Abweichung von Security-Best-Practices: **explizit darauf hinweisen** bevor umgesetzt wird.

### KRITISCHE REGEL: Secrets niemals in den Kontext laden

**Höchste Priorität, gilt ausnahmslos.**

Sobald eine Datei, Variable oder ein Wert ein Secret sein könnte — **nicht lesen, nicht ausgeben,
nicht in den Kontext bringen.** Im Zweifel: lieber zu vorsichtig.

Als Secret gilt: API-Keys, Tokens, Passwörter, Private Keys, Webhook-URLs mit Token-Anteil,
Datenbankpasswörter, Session-Secrets, Client-Secrets, Bearer-Tokens — unabhängig vom Dateinamen.

**Verboten:**
- `.env`-Dateien, `*.pem`, `*.key` lesen (weder mit Read-Tool noch mit `cat`, `head`, etc.)
- `env`, `printenv`, `export -p` ausführen
- Secret-Werte ausgeben, loggen oder in Variablen speichern die im Output erscheinen

**Prüfen ob Wert vorhanden — ohne ihn zu lesen:**
```bash
[ -n "$API_KEY" ] && echo "set" || echo "empty"   # RICHTIG
echo $API_KEY                                       # FALSCH
```

**Wenn Secret versehentlich in Kontext gelangt:**
> ⚠️ SICHERHEITSWARNUNG: Der Wert von [Variable] ist in diesen Kontext gelangt und wurde an
> den LLM-Anbieter übertragen. Umgehend rotieren: 1. Neuen Wert generieren,
> 2. Alten Wert in allen Systemen ungültig machen, 3. Neuen Wert setzen.
