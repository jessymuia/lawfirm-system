// A minimal stand-in for `helm template`, used ONLY because real Helm cannot be installed in every environment.
// It implements just the Sprig/Helm functions this chart uses on top of Go's text/template, so it catches template
// syntax errors, missing values and structural mistakes. It is NOT a substitute for `helm lint` / `helm template`
// (CI runs the real thing); it exists so the chart is never shipped completely unrendered.
//
// usage: go run main.go <chart-dir> <values.json> <release-name>
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"text/template"
)

func toYaml(v interface{}, indent int) string {
	var b strings.Builder
	var walk func(v interface{}, ind int)
	pad := func(n int) string { return strings.Repeat("  ", n) }
	scalar := func(v interface{}) string {
		switch t := v.(type) {
		case string:
			if t == "" || strings.ContainsAny(t, ":#{}[],&*!|>'\"%@`") || t != strings.TrimSpace(t) {
				return strconv.Quote(t)
			}
			return t
		case float64:
			if t == float64(int64(t)) {
				return strconv.FormatInt(int64(t), 10)
			}
			return strconv.FormatFloat(t, 'f', -1, 64)
		case nil:
			return "null"
		default:
			return fmt.Sprint(t)
		}
	}
	walk = func(v interface{}, ind int) {
		switch t := v.(type) {
		case map[string]interface{}:
			keys := make([]string, 0, len(t))
			for k := range t {
				keys = append(keys, k)
			}
			sort.Strings(keys)
			for _, k := range keys {
				switch c := t[k].(type) {
				case map[string]interface{}, []interface{}:
					if isEmpty(c) {
						b.WriteString(pad(ind) + k + ": " + emptyLit(c) + "\n")
					} else {
						b.WriteString(pad(ind) + k + ":\n")
						walk(c, ind+1)
					}
				default:
					b.WriteString(pad(ind) + k + ": " + scalar(c) + "\n")
				}
			}
		case []interface{}:
			for _, it := range t {
				switch c := it.(type) {
				case map[string]interface{}:
					var sub strings.Builder
					old := b
					b = sub
					walk(c, ind+1)
					lines := strings.Split(strings.TrimRight(b.String(), "\n"), "\n")
					b = old
					for i, l := range lines {
						if i == 0 {
							b.WriteString(pad(ind) + "- " + strings.TrimPrefix(l, pad(ind+1)) + "\n")
						} else {
							b.WriteString(l + "\n")
						}
					}
				default:
					b.WriteString(pad(ind) + "- " + scalar(c) + "\n")
				}
			}
		default:
			b.WriteString(pad(ind) + scalar(v) + "\n")
		}
	}
	walk(v, 0)
	return strings.TrimRight(b.String(), "\n")
}

func isEmpty(v interface{}) bool {
	switch t := v.(type) {
	case map[string]interface{}:
		return len(t) == 0
	case []interface{}:
		return len(t) == 0
	}
	return false
}
func emptyLit(v interface{}) string {
	if _, ok := v.(map[string]interface{}); ok {
		return "{}"
	}
	return "[]"
}

func indentStr(n int, s string) string {
	p := strings.Repeat(" ", n)
	lines := strings.Split(s, "\n")
	for i, l := range lines {
		if l != "" {
			lines[i] = p + l
		}
	}
	return strings.Join(lines, "\n")
}

func truthy(v interface{}) bool {
	switch t := v.(type) {
	case nil:
		return false
	case string:
		return t != ""
	case bool:
		return t
	case float64:
		return t != 0
	case map[string]interface{}:
		return len(t) > 0
	case []interface{}:
		return len(t) > 0
	}
	return true
}

func main() {
	chart, valuesFile, release := os.Args[1], os.Args[2], os.Args[3]
	raw, err := os.ReadFile(valuesFile)
	check(err)
	var values map[string]interface{}
	check(json.Unmarshal(raw, &values))

	chartMeta := map[string]interface{}{"Name": "lawfirm", "AppVersion": "9.9.9-test", "Version": "9.9.9-test"}
	data := map[string]interface{}{
		"Values":  values,
		"Release": map[string]interface{}{"Name": release, "Namespace": "default", "Service": "Helm", "Revision": 1, "IsInstall": true},
		"Chart":   chartMeta,
	}

	var tpl *template.Template
	funcs := template.FuncMap{
		"include": func(name string, d interface{}) (string, error) {
			var buf bytes.Buffer
			err := tpl.ExecuteTemplate(&buf, name, d)
			return buf.String(), err
		},
		"toYaml":     func(v interface{}) string { return toYaml(v, 0) },
		"nindent":    func(n int, s string) string { return "\n" + indentStr(n, s) },
		"indent":     func(n int, s string) string { return indentStr(n, s) },
		"quote":      func(v interface{}) string { return strconv.Quote(fmt.Sprint(unfloat(v))) },
		"default":    func(d, v interface{}) interface{} { if truthy(v) { return v }; return d },
		"trunc":      func(n int, s string) string { if len(s) > n { return s[:n] }; return s },
		"trimSuffix": func(suf, s string) string { return strings.TrimSuffix(s, suf) },
		"int":        func(v interface{}) int { switch t := v.(type) { case float64: return int(t); case int: return t; case string: n, _ := strconv.Atoi(t); return n }; return 0 },
		"required": func(msg string, v interface{}) (interface{}, error) {
			if !truthy(v) {
				return nil, errors.New(msg)
			}
			return v, nil
		},
		"fail": func(msg string) (string, error) { return "", errors.New(msg) },
	}
	tpl = template.New("chart").Funcs(funcs).Option("missingkey=error")

	files, _ := filepath.Glob(filepath.Join(chart, "templates", "*"))
	for _, f := range files {
		b, err := os.ReadFile(f)
		check(err)
		_, err = tpl.New(filepath.Base(f)).Parse(string(b))
		check(err)
	}
	for _, f := range files {
		base := filepath.Base(f)
		if base == "_helpers.tpl" || base == "NOTES.txt" {
			continue
		}
		var buf bytes.Buffer
		if err := tpl.ExecuteTemplate(&buf, base, data); err != nil {
			fmt.Fprintf(os.Stderr, "RENDER ERROR in %s: %v\n", base, err)
			os.Exit(3)
		}
		if strings.TrimSpace(buf.String()) == "" {
			continue
		}
		fmt.Printf("---\n# Source: %s\n%s\n", base, buf.String())
	}
}

func unfloat(v interface{}) interface{} {
	if f, ok := v.(float64); ok && f == float64(int64(f)) {
		return int64(f)
	}
	return v
}

func check(err error) {
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(2)
	}
}
