{{- define "lawfirm.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "lawfirm.fullname" -}}
{{- printf "%s-%s" .Release.Name (include "lawfirm.name" .) | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "lawfirm.image" -}}
{{- printf "%s:%s" .Values.image.repository (default .Chart.AppVersion .Values.image.tag) -}}
{{- end -}}

{{- define "lawfirm.labels" -}}
app.kubernetes.io/name: {{ include "lawfirm.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end -}}

{{- define "lawfirm.selector" -}}
app.kubernetes.io/name: {{ include "lawfirm.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}

{{- define "lawfirm.secretName" -}}
{{- if .Values.existingSecret -}}{{ .Values.existingSecret }}{{- else -}}{{ include "lawfirm.fullname" . }}{{- end -}}
{{- end -}}

{{- define "lawfirm.claimName" -}}
{{- if .Values.persistence.existingClaim -}}{{ .Values.persistence.existingClaim }}{{- else -}}{{ include "lawfirm.fullname" . }}-storage{{- end -}}
{{- end -}}

{{/* Environment shared by every role. Secrets come from the Secret, never from the ConfigMap. */}}
{{- define "lawfirm.env" -}}
- name: APP_ENV
  value: production
- name: APP_DEBUG
  value: "false"
- name: APP_NAME
  value: {{ .Values.app.name | quote }}
- name: APP_URL
  value: {{ .Values.app.url | quote }}
- name: SANCTUM_STATEFUL_DOMAINS
  value: {{ .Values.app.statefulDomains | quote }}
- name: CORS_ALLOWED_ORIGINS
  value: {{ .Values.app.url | quote }}
- name: TRUSTED_PROXIES
  value: {{ .Values.app.trustedProxies | quote }}
- name: TENANCY_MODE
  value: single
- name: LOG_CHANNEL
  value: stderr_json
- name: LOG_LEVEL
  value: {{ .Values.app.logLevel | quote }}
- name: MATTER_ACCESS
  value: {{ .Values.app.matterAccess | default "firm" | quote }}
- name: LICENSE_REQUIRED
  value: {{ .Values.app.license.required | quote }}
- name: LICENSE_PUBLIC_KEYS
  value: {{ .Values.app.license.publicKeys | quote }}
- name: DB_CONNECTION
  value: mysql
- name: DB_HOST
  value: {{ required "database.host is required" .Values.database.host | quote }}
- name: DB_PORT
  value: {{ .Values.database.port | quote }}
- name: DB_DATABASE
  value: {{ .Values.database.name | quote }}
- name: DB_USERNAME
  value: {{ .Values.database.username | quote }}
- name: SEARCH_ENGINE
  value: {{ .Values.search.engine | default "database" | quote }}
{{- if eq (.Values.search.engine | default "database") "meilisearch" }}
- name: MEILISEARCH_HOST
  value: {{ required "search.meilisearchHost is required for the meilisearch engine" .Values.search.meilisearchHost | quote }}
- name: MEILISEARCH_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "lawfirm.secretName" . }}
      key: MEILISEARCH_KEY
      optional: true
{{- end }}
{{- if .Values.redis.host }}
- name: QUEUE_CONNECTION
  value: redis
- name: CACHE_DRIVER
  value: redis
- name: SESSION_DRIVER
  value: redis
- name: REDIS_HOST
  value: {{ .Values.redis.host | quote }}
- name: REDIS_PORT
  value: {{ .Values.redis.port | quote }}
- name: REDIS_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "lawfirm.secretName" . }}
      key: REDIS_PASSWORD
      optional: true
{{- else }}
- name: QUEUE_CONNECTION
  value: database
{{- end }}
- name: MAIL_MAILER
  value: {{ .Values.mail.mailer | quote }}
- name: MAIL_HOST
  value: {{ .Values.mail.host | quote }}
- name: MAIL_PORT
  value: {{ .Values.mail.port | quote }}
- name: MAIL_USERNAME
  value: {{ .Values.mail.username | quote }}
- name: MAIL_ENCRYPTION
  value: {{ .Values.mail.encryption | quote }}
- name: MAIL_FROM_ADDRESS
  value: {{ .Values.mail.fromAddress | quote }}
- name: INSTALL_ADMIN_EMAIL
  value: {{ .Values.installAdmin.email | quote }}
- name: INSTALL_ADMIN_NAME
  value: {{ .Values.installAdmin.name | quote }}
- name: APP_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "lawfirm.secretName" . }}
      key: APP_KEY
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "lawfirm.secretName" . }}
      key: DB_PASSWORD
- name: INSTALL_ADMIN_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "lawfirm.secretName" . }}
      key: INSTALL_ADMIN_PASSWORD
      optional: true
- name: MAIL_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "lawfirm.secretName" . }}
      key: MAIL_PASSWORD
      optional: true
{{- end -}}

{{/* The shared storage volume (documents, logs). Mounted by web, queue and scheduler. */}}
{{- define "lawfirm.storageVolume" -}}
- name: storage
  {{- if .Values.persistence.enabled }}
  persistentVolumeClaim:
    claimName: {{ include "lawfirm.claimName" . }}
  {{- else }}
  emptyDir: {}
  {{- end }}
{{- end -}}
