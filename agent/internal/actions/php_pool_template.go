package actions

import (
	"bytes"
	"fmt"
	"text/template"
)

type PhpPoolTemplateData struct {
	Username        string
	Version         string
	PmMode          string
	MaxChildren     int
	StartServers    int
	MinSpareServers int
	MaxSpareServers int
	Settings        PhpIniSettings
}

const fpmPoolTemplate = `; HPanel PHP-FPM Pool Configuration
; User: {{.Username}}
; PHP Version: {{.Version}}
; Managed atomically by panel-agent. Do not edit manually.

[{{.Username}}]
user = {{.Username}}
group = {{.Username}}

listen = /run/php/php{{.Version}}-fpm-{{.Username}}.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = {{.PmMode}}
pm.max_children = {{.MaxChildren}}
{{if eq .PmMode "ondemand"}}
pm.process_idle_timeout = 10s
pm.max_requests = 500
{{else if eq .PmMode "dynamic"}}
pm.start_servers = {{.StartServers}}
pm.min_spare_servers = {{.MinSpareServers}}
pm.max_spare_servers = {{.MaxSpareServers}}
pm.max_requests = 1000
{{end}}

pm.status_path = /status-{{.Username}}
ping.path = /ping-{{.Username}}

; Logging and security isolation
access.log = /home/{{.Username}}/logs/fpm_access.log
catch_workers_output = yes
decorate_workers_output = no

; Open basedir & account isolation
php_admin_value[open_basedir] = /home/{{.Username}}/:/tmp/:/var/tmp/
php_admin_value[upload_tmp_dir] = /home/{{.Username}}/tmp
php_admin_value[session.save_path] = /home/{{.Username}}/tmp
php_admin_value[error_log] = /home/{{.Username}}/logs/php_error.log
php_admin_flag[log_errors] = on

; Custom PHP INI Overrides
{{if .Settings.MemoryLimit}}
php_admin_value[memory_limit] = {{.Settings.MemoryLimit}}
{{end}}
{{if .Settings.UploadMaxFilesize}}
php_admin_value[upload_max_filesize] = {{.Settings.UploadMaxFilesize}}
{{end}}
{{if .Settings.PostMaxSize}}
php_admin_value[post_max_size] = {{.Settings.PostMaxSize}}
{{end}}
{{if .Settings.MaxExecutionTime}}
php_admin_value[max_execution_time] = {{.Settings.MaxExecutionTime}}
{{end}}
{{if .Settings.DisplayErrors}}
php_flag[display_errors] = {{.Settings.DisplayErrors}}
{{end}}
`

// RenderPhpPoolConfig generates the PHP-FPM pool configuration.
func RenderPhpPoolConfig(data PhpPoolTemplateData) (string, error) {
	if data.PmMode == "" {
		data.PmMode = "ondemand"
	}
	if data.MaxChildren <= 0 {
		data.MaxChildren = 5
	}
	if data.PmMode == "dynamic" {
		if data.StartServers <= 0 {
			data.StartServers = 2
		}
		if data.MinSpareServers <= 0 {
			data.MinSpareServers = 1
		}
		if data.MaxSpareServers <= 0 {
			data.MaxSpareServers = 3
		}
	}

	tmpl, err := template.New("fpmPool").Parse(fpmPoolTemplate)
	if err != nil {
		return "", fmt.Errorf("parsing fpm pool template: %w", err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return "", fmt.Errorf("executing fpm pool template: %w", err)
	}

	return buf.String(), nil
}

