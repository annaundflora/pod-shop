# Beispiel: Checkpoint Tasks

Dieses Dokument zeigt alle drei Checkpoint-Types mit typischen Use Cases.

---

## Beispiel 1: Checkpoint nach Deployment (human-verify)

```xml
<task type="auto">
  <name>Deploy to Vercel</name>
  <files>vercel.json</files>
  <action>Run vercel --yes</action>
  <verify>vercel ls shows deployment</verify>
  <done>Deployment complete</done>
</task>

<task type="checkpoint:human-verify">
  <name>Verify Deployment</name>
  <files></files>
  <verify>
  1. Open deployment URL: https://my-app.vercel.app
  2. Check homepage loads correctly
  3. Test navigation between pages
  4. Verify database connections work
  5. Test user authentication flow
  </verify>
  <done>Deployment verified working correctly</done>
</task>
```

**Use Case:** Nach automatischem Deployment muss ein Mensch visuell verifizieren, dass alles funktioniert.

---

## Beispiel 2: Authentifikations-Methode wählen (decision)

```xml
<task type="checkpoint:decision">
  <name>Choose Authentication Method</name>
  <files></files>
  <verify>
  Option A: JWT with httpOnly cookies (more secure, standard)
  Option B: Session-based with Redis (simpler, stateful)
  Option C: OAuth2 only (external provider, no local users)

  Context:
  - App is public-facing with user accounts
  - Need to support password reset
  - Mobile app will consume the API
  </verify>
  <done>Authentication method selected and documented</done>
</task>
```

**Use Case:** Architektonische Entscheidung muss getroffen werden, bevor die Implementierung beginnen kann.

---

## Beispiel 3: Manuelle Vercel-Authentifizierung (human-action)

```xml
<task type="checkpoint:human-action">
  <name>Vercel Authentication</name>
  <files></files>
  <action>Attempted vercel --prod deployment</action>
  <verify>
  1. Open terminal
  2. Run: vercel login
  3. Follow browser authentication flow
  4. Verify with: vercel whoami

  Note: Vercel CLI requires interactive browser login,
  cannot be automated in CI/CD pipeline.
  </verify>
  <done>vercel whoami returns your account email</done>
</task>
```

**Use Case:** Ein Schritt ist technisch nicht automatisierbar und erfordert manuelle Interaktion.

---

## Checkpoint-Format in PLAN.md

### checkpoint:human-verify (~90% der Fälle)

Am häufigsten verwendet. Pausiert die Ausführung für manuelle Verifikation.

**Typische Szenarien:**
- Nach Deployment/Release
- Nach UI-Änderungen
- Nach Datenbank-Migrationen
- Vor Merge-Request

**User-Antworten:**
- `approved` - Verifikation bestanden, weiter
- `fix` - Fehler gefunden, Fix erforderlich
- `skip` - Checkpoint überspringen

### checkpoint:decision (~9% der Fälle)

User muss zwischen Implementation-Optionen wählen.

**Typische Szenarien:**
- Architektur-Entscheidungen
- Library/Framework Auswahl
- Datenbank-Schema Design
- API-Design

**User-Antworten:**
- `option-A`, `option-B`, etc. - Option wählen
- Custom input - Eigener Lösungsvorschlag

### checkpoint:human-action (~1% der Fälle)

Unvermeidbarer manueller Schritt.

**Typische Szenarien:**
- Browser-basierte Authentifizierung
- Manuelle Schritte in externen Services
- Hardware-basierte Operationen
- Rechtliche/Compliance-Checks

**User-Antworten:**
- `done` - Aktion ausgeführt, weiter
- `skip` - Aktion überspringen

---

## Vollständiges Beispiel mit allen Checkpoint-Types

```xml
---
phase: 1
plan: 99
type: execute
depends_on: None
files_modified: [vercel.json, README.md]
domain: deployment
---

<objective>
Beispiel-Plan demonstrating alle Checkpoint Types
</objective>

<context>
Zeigt wie Checkpoints in PLAN.md verwendet werden
</context>

<tasks>

<task type="auto">
  <name>Setup Project</name>
  <files>package.json</files>
  <action>npm install</action>
  <verify>test -d node_modules</verify>
  <done>Dependencies installed</done>
</task>

<task type="checkpoint:decision">
  <name>Choose Deployment Platform</name>
  <files></files>
  <verify>
  Option A: Vercel (recommended for Next.js)
  Option B: Netlify (good for static sites)
  Option C: AWS Amplify (enterprise features)
  </verify>
  <done>Platform selected</done>
</task>

<task type="auto">
  <name>Configure Deployment</name>
  <files>vercel.json</files>
  <action>Create vercel.json with build settings</action>
  <verify>test -f vercel.json</verify>
  <done>Deployment config created</done>
</task>

<task type="checkpoint:human-action">
  <name>Connect Deployment Account</name>
  <files></files>
  <action>Attempted vercel link</action>
  <verify>
  Run: vercel login
  Follow browser authentication
  Verify: vercel whoami
  </verify>
  <done>Deployment account connected</done>
</task>

<task type="auto">
  <name>Deploy to Preview</name>
  <files></files>
  <action>vercel --yes</action>
  <verify>vercel ls | grep -q Preview</verify>
  <done>Preview deployment created</done>
</task>

<task type="checkpoint:human-verify">
  <name>Verify Preview Deployment</name>
  <files></files>
  <verify>
  1. Open preview URL from vercel ls output
  2. Check homepage loads
  3. Test navigation
  4. Verify no console errors
  </verify>
  <done>Preview verified working</done>
</task>

</tasks>

<verification>
Before declaring plan complete:
- [ ] All checkpoints passed
- [ ] Deployment platform selected
- [ ] Preview deployment verified
</verification>

<success_criteria>
- User understands all 3 checkpoint types
- Checkpoint protocol demonstrated
- Resume after checkpoint works
</success_criteria>
```

---

## Best Practices

1. **Checkpoints sparsam einsetzen** - Nur wenn wirklich nötig
2. **Klare Instruktionen** - User muss wissen was zu tun ist
3. **Verifizierbar** - Done criteria muss testbar sein
4. **Kontext geben** - Warum ist dieser Checkpoint nötig?
5. **Resume-friendly** - Checkpoint sollte leicht zu resumen sein

---

## Integration mit GSD Executor

Der Executor erkennt Tasks mit `type="checkpoint:*"` automatisch:

1. **Vor Task-Ausführung:** Prüft `task.isCheckpoint`
2. **Bei Checkpoint:** Stoppt sofort, gibt strukturierte Message zurück
3. **Nach User-Input:** Fresh Agent wird gespawnt, setzt ab resumePoint fort

---

*Dieses Dokument ist Teil der GSD Executor Dokumentation*
