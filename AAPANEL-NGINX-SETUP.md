# Configuração Nginx no aaPanel

## 📌 Guia Completo para img.hoststorm.cloud

### Passo 1: Acessar Configuração do Site

1. Faça login no **aaPanel**
2. Vá em **Website**
3. Encontre **img.hoststorm.cloud**
4. Clique no botão **Settings** (engrenagem)

### Passo 2: Editar Configuração do Nginx

1. No menu lateral, clique em **Site directory** ou **Config files**
2. Clique na aba **Config Modify** ou **Nginx Config**
3. Você verá o arquivo de configuração do Nginx

### Passo 3: Adicionar as Regras

Procure pela seção que começa com:
```nginx
location / {
```

**ANTES** dessa seção, adicione:

```nginx
# WebP Converter API - Routes

# Bloquear arquivos sensíveis
location ~ /\.(git|env|htaccess) {
    deny all;
    return 404;
}

location ~ ^/(config|app)/ {
    deny all;
    return 404;
}

location ~ ^/storage/(incoming|logs)/ {
    deny all;
    return 404;
}

# API endpoints
location ~ ^/api/v1/ {
    try_files $uri $uri/ /index.php?$query_string;
}

# Download endpoint
location ~ ^/download/(.+)$ {
    rewrite ^/download/(.+)$ /public/download.php last;
}
```

### Exemplo Completo:

```nginx
server {
    listen 80;
    server_name img.hoststorm.cloud;
    root /www/wwwroot/img.hoststorm.cloud;
    index index.php index.html;
    
    # ===== ADICIONE ESTAS LINHAS =====
    
    # Bloquear arquivos sensíveis
    location ~ /\.(git|env|htaccess) {
        deny all;
        return 404;
    }
    
    location ~ ^/(config|app)/ {
        deny all;
        return 404;
    }
    
    location ~ ^/storage/(incoming|logs)/ {
        deny all;
        return 404;
    }
    
    # API endpoints
    location ~ ^/api/v1/ {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # Download endpoint
    location ~ ^/download/(.+)$ {
        rewrite ^/download/(.+)$ /public/download.php last;
    }
    
    # Cache para WebP
    location ~* \.webp$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
    
    # ===== ATÉ AQUI =====
    
    # Resto da configuração existente...
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/tmp/php-cgi-84.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Passo 4: Salvar e Reiniciar

1. Clique em **Save**
2. Vá em **App Store** > **Nginx** > **Reload** ou **Restart**

Ou pelo terminal:
```bash
sudo nginx -t  # Testar configuração
sudo nginx -s reload  # Recarregar
```

### Passo 5: Testar

Acesse:
```
https://img.hoststorm.cloud/api/v1/health
```

Deve retornar:
```json
{
  "status": "ok",
  "timestamp": "2026-01-21T...",
  "capabilities": {
    "imagick": true,
    "gd": true
  }
}
```

---

## ⚡ Solução Rápida (aaPanel Interface)

Se o aaPanel tiver uma interface visual:

1. **Website** > **img.hoststorm.cloud** > **Settings**
2. **Rewrite** (ou **URL Rewrite**)
3. Selecione **Custom** ou **Other**
4. Cole o conteúdo do arquivo `nginx.conf` deste repositório
5. **Save**
6. **Reload Nginx**

---

## 🐞 Troubleshooting

### Erro 404 ainda aparece?

**1. Verifique se o Nginx recarregou:**
```bash
sudo nginx -t
sudo systemctl status nginx
```

**2. Verifique logs do Nginx:**
```bash
tail -f /www/wwwlogs/img.hoststorm.cloud.error.log
```

**3. Teste acesso direto ao index.php:**
```
https://img.hoststorm.cloud/index.php
```

Se funcionar, o problema é nas regras de rewrite.

**4. Verifique o socket PHP:**

No aaPanel, o socket pode ser:
- `/tmp/php-cgi-84.sock` (PHP 8.4)
- `/tmp/php-cgi-81.sock` (PHP 8.1)
- `127.0.0.1:9000` (TCP)

Verifique qual está configurado no seu Nginx.

### Erro 502 Bad Gateway?

O PHP-FPM pode estar parado:
```bash
sudo systemctl restart php-fpm-84  # ou php-fpm-81
```

### Permissões?

Garanta que o usuário do Nginx tem acesso:
```bash
sudo chown -R www:www /www/wwwroot/img.hoststorm.cloud
sudo chmod -R 755 /www/wwwroot/img.hoststorm.cloud
sudo chmod -R 777 /www/wwwroot/img.hoststorm.cloud/storage
```

---

## 🎯 Configuração Alternativa (Se nada funcionar)

Crie um arquivo `.user.ini` na raiz:

```ini
upload_max_filesize = 16M
post_max_size = 16M
max_execution_time = 300
memory_limit = 256M
```

E use esta configuração mínima no Nginx:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}

location ~ ^/api/ {
    try_files $uri /index.php?$args;
}

location ~ \.php$ {
    fastcgi_pass unix:/tmp/php-cgi-84.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

---

## ✅ Checklist Final

- [ ] Regras adicionadas no Nginx config
- [ ] Nginx recarregado com sucesso (`nginx -t` passou)
- [ ] `/api/v1/health` retorna JSON
- [ ] `install.php` deletado
- [ ] `test.php` deletado (opcional, para segurança)
- [ ] Cron job configurado para `worker.php`
- [ ] API Key salva em local seguro

---

**Precisa de ajuda?** Envie:
1. Output de `sudo nginx -t`
2. Conteúdo do arquivo de config do Nginx (censure senhas)
3. Últimas linhas do error.log
