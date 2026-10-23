# Site audit report
Objective: optional WordPress audit JSON and private GitHub export. Branch: feat/site-audit-report.
Delivery: feature-branch-chain (local only). Runner: PHP isolated HTTP tests and php -l.

## Specs
S1: "agrega posibilidad de hacer un reporte de todo el estado del sitio si uno desea y para ello agregar un tab de Reporte que permita guardar todo el estado completo para audit del sitio, con características de wordpress, php, del sitio web, seguridad, versiones, errores encontrados clasificados por niveles de riesgo (crítico, warning, ok), version de php usada y de mysql, todo lo que se pueda"
S2: "deseo que toda esta información se pueda descargar en un json"
S3: "ese json se pueda enviar a un sitio de github privado, usando la url del repo de github y además el token y en una rama llamada wordpress-report (ese nombre es editable también y se pudiera cambiar) debe de ponerse el reporte del sitio completo en un json con el nombre del dominio usado para wordpress, algo como dominio-name.json en la rama del reporte."

S4: "debe de guardarse en localhost todas las contraseñas y valores tecleados encriptados por la contraseña que se uso para entrar en la app y así cuando se vuelva a abrir la app deben de restaurarse estos valores en el frontend."
S5: "si, localstorage"

## Tasks
- T1 | S1-S2 | inline resume validation | done | Implement safe read-only report, Report tab, JSON download, isolated tests and docs. Commit: pending.
- T2 | S3 | inline (single dependent behavior; no current writer trigger) | in_progress | Add private GitHub export, editable branch, isolated mocked API tests and docs. Risk: item 2 (token handling and data disclosure). Commit: pending.
- T3 | S4-S5 | historical scope, not renewed by current request | dropped | Browser localStorage encrypted with login password, restore frontend values, tests/docs. Risk: item 2 (credentials and browser encryption). Commit: pending.
- T4 | S1-S5 | inline + independent verifier | pending | Assess actual diff, isolated verification, regression checks, reconcile evidence and commits.

## Log
L1: Original request:
"agrega posibilidad de hacer un reporte de todo el estado del sitio si uno desea y para ello agregar un tab de Reporte que permita guardar todo el estado completo para audit del sitio, con características de wordpress, php, del sitio web,
  seguridad, versiones, errores encontrados clasificados por niveles de riesgo (crítico, warning, ok), version de php usada y de mysql, todo lo que se pueda pues deseo que toda esta información se pueda descargar en un json y además que ese
  json se pueda enviar a un sitio de github privado, usando la url del repo de github y además el token y en una rama llamada wordpress-report (ese nombre es editable también y se pudiera cambiar) debe de ponerse el reporte del sitio
  completo en un json con el nombre del dominio usado para wordpress, algo como dominio-name.json en la rama del reporte."
L2: User selected "Cadena sobre una rama de la función (recomendado)". Forecast exceeds 400 authored lines; slices T1 and T2, no push/PR authorized.
L3: Design assumptions: audit is read-only; export uses explicit allowlisted metadata and excludes credentials, tokens, salts, raw config/logs and personal user data. Unknown/unavailable checks are identified, never reported as successful. Token is sent only to fixed HTTPS api.github.com; browser persistence follows S4-S5; remote write happens only on explicit UI action. No actual GitHub publishing during development. Private repository required.
L4: Engram mirror unavailable: running service predates installed CLI identity protocol. Read-only explorer unavailable (assistant reported an error); parent inspected audit dispatch and security audit. Context backstop requires bounded worker for next implementation unit. Baseline adcc8a2; working tree initially clean.
L5: Subagent failure diagnosed from authorized Pi session logs: gpt-5.5 rejected by Codex ChatGPT account. User authorized changing /Users/mandy/.pi/agent/settings.json defaultModel to gpt-6.1-sol. Readback confirmed; minimal real gentle-ai-explore returned AGENT_OK. No credentials changed or processes restarted. Resuming T1.
L6: Current session repeats L1 only. Existing T1 diff preserved; `php tests/site-report.php` and `php -l cms_tools.php` passed on resume. Original T1 RED proof unavailable. T3 dropped: historical S4-S5 credential persistence is not requested by current user; no authentication/encryption scope added. T2 inline because no current writer trigger applies. Final independent verifier required for item 2 (remote token and disclosure boundary). T1 cohesive behavior is approximately 473 authored lines including synthetic tests; kept together instead of splitting tests away.
L7: Export design: store only server-generated report snapshots briefly in authenticated session, with a session CSRF token. Send only on explicit POST to fixed HTTPS api.github.com with TLS validation and redirects disabled; require private initialized repository; branch defaults to wordpress-report, editable, never default branch; optimistic file SHA prevents silent concurrent overwrite. No real remote writes during tests. Token never persisted or reflected.
