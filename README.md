# WebP Converter API

📸 Microserviço PHP puro para conversão de imagens para WebP com suporte a filas e integração n8n.

## 🎯 Objetivo

Substituir serviços externos (CloudConvert, Nutrient) oferecendo conversão local de imagens (JPG/PNG/GIF/AVIF) para WebP com:
- ✅ Controle total de qualidade e processamento
- ✅ Suporte a URLs e uploads diretos
- ✅ Sistema de filas para processamento assíncrono
- ✅ API REST simples para integração com n8n
- ✅ Autenticação por API Key e rate limiting
- ✅ Proteção contra SSRF e validação rigorosa

## 👨‍💻 Stack Técnica

- **PHP 8.1+** (sem frameworks)
- **MySQL 5.7+** / MariaDB
- **Imagick** (preferencial) ou **GD** + **cwebp**
- Storage local em `/storage`
- Apache com `.htaccess` ou Nginx

## 🚀 Instalação

### 1. Upload via FTP

```bash
# Baixe o repositório
git clone https://github.com/danilostorm/webp-converter-api.git

# Faça upload de todos os arquivos via FTP para seu servidor
# Certifique-se de incluir a estrutura de pastas:
# - app/
# - config/
# - public/
# - storage/
# - index.php
# - worker.php
# - install.php
# - schema.sql
```

### 2. Permissões

```bash
chmod -R 755 storage/
chmod 755 config/
```

### 3. Instalação Web

Acesse `http://seu-dominio.com/install.php` e siga o assistente:

1. **Verificação de requisitos** - checa extensões PHP e permissões
2. **Configuração do banco** - cria database e tabelas
3. **Geração de API Key** - cria sua primeira chave

⚠️ **IMPORTANTE:** Após a instalação, **delete o arquivo `install.php`** por segurança!

### 4. Configurar Worker (Cron)

Para processar jobs em background, adicione ao crontab:

```bash
crontab -e

# Adicione esta linha (processa jobs a cada minuto):
* * * * * cd /path/to/webp-converter-api && php worker.php >> /dev/null 2>&1
```

Ou execute manualmente:
```bash
php worker.php
```

### 5. Configuração do Servidor Web

#### Apache (já incluído `.htaccess`)

O `.htaccess` já está configurado. Certifique-se de ter `mod_rewrite` ativo:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Nginx

```nginx
server {
    listen 80;
    server_name seu-dominio.com;
    root /path/to/webp-converter-api;
    index index.php;

    # Download endpoint
    location ~ ^/download/([a-f0-9\-]+)(?:\.webp)?$ {
        try_files /public/download.php =404;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/public/download.php;
        include fastcgi_params;
    }

    # API endpoints
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Bloquear acesso a arquivos sensíveis
    location ~ /\.(git|env) {
        deny all;
    }

    location ~ /config/ {
        deny all;
    }
}
```

## 📚 API Documentation

### Autenticação

Todas as requisições precisam do header:

```http
X-API-Key: wca_sua_chave_aqui
```

### Endpoints

#### 1. Health Check

```http
GET /api/v1/health
```

**Resposta:**
```json
{
  "status": "ok",
  "timestamp": "2026-01-21T15:30:00Z",
  "capabilities": {
    "imagick": true,
    "gd": true,
    "gd_webp": true,
    "cwebp": false
  }
}
```

#### 2. Criar Job (Assíncrono)

**Por URL:**
```http
POST /api/v1/jobs
Content-Type: application/json
X-API-Key: wca_sua_chave

{
  "source_url": "https://example.com/image.jpg",
  "output_format": "webp",
  "quality": 85,
  "width": 1280,
  "height": null,
  "fit": "contain",
  "strip_metadata": true,
  "filename": "converted.webp"
}
```

**Por Upload:**
```http
POST /api/v1/jobs
Content-Type: multipart/form-data
X-API-Key: wca_sua_chave

file: (binary)
quality: 85
width: 1280
fit: contain
```

**Resposta (201):**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "queued",
  "poll_url": "https://api.example.com/api/v1/jobs/550e8400-e29b-41d4-a716-446655440000",
  "result_url": null
}
```

#### 3. Consultar Job

```http
GET /api/v1/jobs/{job_id}
X-API-Key: wca_sua_chave
```

**Resposta (status=done):**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "done",
  "progress": 100,
  "result_url": "https://api.example.com/download/550e8400-e29b-41d4-a716-446655440000.webp",
  "error": null,
  "meta": {
    "input_mime": "image/jpeg",
    "input_size": 2458320,
    "output_size": 892156,
    "time_ms": 1234,
    "created_at": "2026-01-21T15:30:00Z",
    "finished_at": "2026-01-21T15:30:01Z"
  }
}
```

**Status possíveis:** `queued`, `processing`, `done`, `error`

#### 4. Conversão Síncrona (Modo Simples)

```http
POST /api/v1/convert
Content-Type: application/json
X-API-Key: wca_sua_chave

{
  "source_url": "https://example.com/small-image.jpg",
  "quality": 85
}
```

**Resposta:**
```json
{
  "success": true,
  "result_url": "https://api.example.com/download/xxx.webp",
  "job_id": "xxx"
}
```

Ou com `Accept: image/webp` retorna o binário diretamente.

#### 5. Download

```http
GET /download/{job_id}.webp
```

Retorna o arquivo WebP com headers apropriados (cache, ETag, range support).

### Parâmetros de Conversão

| Parâmetro | Tipo | Padrão | Descrição |
|-----------|------|--------|-------------|
| `quality` | int | 85 | Qualidade WebP (0-100) |
| `width` | int | null | Largura em pixels (null = manter) |
| `height` | int | null | Altura em pixels (null = manter) |
| `fit` | string | contain | Modo de ajuste: `contain`, `cover`, `inside`, `outside` |
| `strip_metadata` | bool | true | Remover metadados EXIF |
| `filename` | string | null | Nome do arquivo de saída |

### Códigos de Erro

| Código HTTP | Error Code | Descrição |
|-------------|------------|-------------|
| 400 | INVALID_URL | URL inválida ou bloqueada (SSRF) |
| 400 | INVALID_PARAMETERS | Parâmetros inválidos |
| 401 | INVALID_API_KEY | API Key inválida ou inativa |
| 413 | FILE_TOO_LARGE | Arquivo excede 15MB |
| 415 | UNSUPPORTED_MEDIA | Tipo de imagem não suportado |
| 429 | RATE_LIMIT_EXCEEDED | Limite de requisições excedido |
| 500 | CONVERSION_FAILED | Falha na conversão |

**Formato de erro:**
```json
{
  "error": {
    "code": "INVALID_URL",
    "message": "URL is blocked for security reasons",
    "details": {
      "url": "http://localhost/test.jpg",
      "reason": "Private IP range"
    }
  }
}
```

## 🔗 Integração com n8n

### Workflow 1: Conversão Assíncrona (Recomendado)

```json
{
  "nodes": [
    {
      "name": "Trigger",
      "type": "n8n-nodes-base.manualTrigger"
    },
    {
      "name": "Create Job",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "method": "POST",
        "url": "https://seu-dominio.com/api/v1/jobs",
        "authentication": "genericCredentialType",
        "genericAuthType": "httpHeaderAuth",
        "sendHeaders": true,
        "headerParameters": {
          "parameters": [
            {
              "name": "X-API-Key",
              "value": "wca_sua_chave_aqui"
            }
          ]
        },
        "sendBody": true,
        "bodyParameters": {
          "parameters": [
            {
              "name": "source_url",
              "value": "={{ $json.imageUrl }}"
            },
            {
              "name": "quality",
              "value": 85
            },
            {
              "name": "width",
              "value": 1280
            },
            {
              "name": "fit",
              "value": "contain"
            }
          ]
        }
      }
    },
    {
      "name": "Wait 2s",
      "type": "n8n-nodes-base.wait",
      "parameters": {
        "amount": 2
      }
    },
    {
      "name": "Poll Job Status",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "method": "GET",
        "url": "={{ $json.poll_url }}",
        "sendHeaders": true,
        "headerParameters": {
          "parameters": [
            {
              "name": "X-API-Key",
              "value": "wca_sua_chave_aqui"
            }
          ]
        }
      }
    },
    {
      "name": "Check Status",
      "type": "n8n-nodes-base.if",
      "parameters": {
        "conditions": {
          "string": [
            {
              "value1": "={{ $json.status }}",
              "value2": "done"
            }
          ]
        }
      }
    },
    {
      "name": "Download WebP",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "method": "GET",
        "url": "={{ $json.result_url }}",
        "responseFormat": "file"
      }
    },
    {
      "name": "Upload to WordPress",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "method": "POST",
        "url": "https://seu-site.com/wp-json/wp/v2/media",
        "authentication": "genericCredentialType",
        "sendBody": true,
        "contentType": "multipart-form-data",
        "bodyParameters": {
          "parameters": [
            {
              "name": "file",
              "inputDataFieldName": "data"
            }
          ]
        }
      }
    }
  ]
}
```

### Workflow 2: Conversão Síncrona Simples

```json
{
  "nodes": [
    {
      "name": "Convert Image",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "method": "POST",
        "url": "https://seu-dominio.com/api/v1/convert",
        "sendHeaders": true,
        "headerParameters": {
          "parameters": [
            {
              "name": "X-API-Key",
              "value": "wca_sua_chave_aqui"
            }
          ]
        },
        "sendBody": true,
        "bodyParameters": {
          "parameters": [
            {
              "name": "source_url",
              "value": "={{ $json.imageUrl }}"
            },
            {
              "name": "quality",
              "value": 85
            }
          ]
        }
      }
    },
    {
      "name": "Download",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "url": "={{ $json.result_url }}",
        "responseFormat": "file"
      }
    }
  ]
}
```

### Workflow 3: Upload Direto

```json
{
  "nodes": [
    {
      "name": "Upload and Convert",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "method": "POST",
        "url": "https://seu-dominio.com/api/v1/jobs",
        "sendHeaders": true,
        "headerParameters": {
          "parameters": [
            {
              "name": "X-API-Key",
              "value": "wca_sua_chave_aqui"
            }
          ]
        },
        "sendBody": true,
        "contentType": "multipart-form-data",
        "bodyParameters": {
          "parameters": [
            {
              "name": "file",
              "inputDataFieldName": "data"
            },
            {
              "name": "quality",
              "value": "85"
            },
            {
              "name": "width",
              "value": "1280"
            }
          ]
        }
      }
    }
  ]
}
```

## 🔒 Segurança

### Proteção SSRF

A API bloqueia automaticamente:
- IPs privados (127.0.0.1, 10.x.x.x, 192.168.x.x, 172.16-31.x.x)
- Link-local (169.254.x.x)
- Loopback
- Metadata endpoints cloud (169.254.169.254)

### Rate Limiting

Padrão: **60 requisições por minuto** por API Key.

Configurável em `config/config.php`:

```php
'rate_limit' => [
    'default' => 60,
    'window' => 60,
],
```

### Validação de Arquivos

- Tamanho máximo: **15MB** (configurável)
- Tipos aceitos: JPG, PNG, GIF, WebP, AVIF
- Validação MIME real (não só extensão)

### Recomendações

1. Use HTTPS em produção
2. Mantenha API Keys seguras (nunca exponha publicamente)
3. Delete `install.php` após instalação
4. Configure backup regular do banco e storage
5. Monitore logs em `storage/logs/app.log`

## 🛠️ Manutenção

### Logs

```bash
tail -f storage/logs/app.log
```

### Limpar arquivos antigos

```bash
# Limpar jobs concluídos com mais de 7 dias
find storage/output/ -type f -mtime +7 -delete

# Limpar jobs com erro
php -r "require 'app/bootstrap.php'; \$db = Database::getInstance()->getConnection(); \$db->exec('DELETE FROM jobs WHERE status=\"error\" AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');"
```

### Monitorar fila

```sql
SELECT status, COUNT(*) as total 
FROM jobs 
GROUP BY status;
```

### Verificar uso de storage

```bash
du -sh storage/output/
```

## 📊 Performance

### Otimizações Recomendadas

1. **Imagick** é mais rápido que GD - instale se possível:
   ```bash
   sudo apt-get install php-imagick
   ```

2. **OPcache** para PHP:
   ```ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=10000
   ```

3. **MySQL** - índices já estão otimizados no schema

4. **Worker múltiplos** - rode vários workers em paralelo:
   ```bash
   * * * * * cd /path && php worker.php >> /dev/null 2>&1
   * * * * * cd /path && php worker.php >> /dev/null 2>&1
   * * * * * cd /path && php worker.php >> /dev/null 2>&1
   ```

### Benchmarks Médios

| Tamanho | Imagick | GD + cwebp |
|---------|---------|------------|
| 500KB   | ~200ms  | ~400ms     |
| 2MB     | ~600ms  | ~1200ms    |
| 5MB     | ~1500ms | ~3000ms    |

## ❓ Troubleshooting

### Worker não processa jobs

```bash
# Testar worker manualmente
php worker.php

# Verificar se cron está rodando
crontab -l

# Verificar locks travados
SELECT * FROM jobs WHERE locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND status='processing';
```

### Erro "Extension not found"

```bash
# Instalar extensões necessárias
sudo apt-get install php-imagick php-gd php-mysql php-curl
sudo systemctl restart apache2
```

### Permissões negadas

```bash
chmod -R 755 storage/
chown -R www-data:www-data storage/
```

## 📝 Changelog

### v1.0.0 (2026-01-21)
- ✅ Conversão WebP com Imagick/GD/cwebp
- ✅ Sistema de filas com worker
- ✅ API REST completa
- ✅ Autenticação e rate limiting
- ✅ Proteção SSRF
- ✅ Instalador web
- ✅ Documentação n8n

## 📜 Licença

MIT License - Use livremente!

## ❤️ Autor

Desenvolvido por **DaNiLoStOrM** para uso próprio e comunidade.

## 🔗 Links Úteis

- [Documentação WebP](https://developers.google.com/speed/webp)
- [Imagick PHP](https://www.php.net/manual/en/book.imagick.php)
- [n8n Documentation](https://docs.n8n.io/)

---

**🚨 Lembre-se:** Delete `install.php` após a instalação e mantenha suas API Keys seguras!
