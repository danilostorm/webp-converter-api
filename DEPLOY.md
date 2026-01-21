# Guia de Deploy - WebP Converter API

## 📦 Deploy via FTP

### 1. Preparar arquivos localmente

```bash
git clone https://github.com/danilostorm/webp-converter-api.git
cd webp-converter-api
```

### 2. Upload via FTP

Faça upload de todos os arquivos EXCETO:
- `.git/`
- `storage/incoming/*` (apenas a pasta vazia)
- `storage/output/*` (apenas a pasta vazia)
- `storage/logs/*` (apenas a pasta vazia)

### 3. Permissões via SSH

```bash
chmod -R 755 storage/
chmod 755 config/
chmod 644 .htaccess
```

Se não tiver SSH, use o gerenciador de arquivos do cPanel:
- `storage/` e subpastas: 755
- `config/`: 755
- Arquivos `.php`: 644

### 4. Instalação

Acesse: `http://seu-dominio.com/install.php`

Siga o assistente:
1. Verifica requisitos
2. Configura banco de dados
3. Gera API Key

⚠️ **DELETE `install.php` APÓS CONCLUIR!**

### 5. Configurar Cron Job

**cPanel:**
1. Acesse "Cron Jobs"
2. Adicione novo cron:
   - Minuto: `*`
   - Hora: `*`
   - Dia: `*`
   - Mês: `*`
   - Dia da semana: `*`
   - Comando: `/usr/bin/php /home/seu-usuario/public_html/webp-api/worker.php`

**SSH:**
```bash
crontab -e
# Adicione:
* * * * * cd /path/to/webp-converter-api && php worker.php >> /dev/null 2>&1
```

## 🐳 Deploy via Docker

### Dockerfile

```dockerfile
FROM php:8.1-apache

# Instalar extensões
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libmagickwand-dev \
    webp \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo_mysql \
    && pecl install imagick \
    && docker-php-ext-enable imagick

# Ativar mod_rewrite
RUN a2enmod rewrite

# Copiar código
COPY . /var/www/html/

# Permissões
RUN chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 755 /var/www/html/storage

EXPOSE 80
```

### docker-compose.yml

```yaml
version: '3.8'

services:
  api:
    build: .
    ports:
      - "8080:80"
    volumes:
      - ./storage:/var/www/html/storage
      - ./config:/var/www/html/config
    environment:
      - DB_HOST=mysql
      - DB_PORT=3306
      - DB_NAME=webp_converter
      - DB_USER=root
      - DB_PASS=secret
    depends_on:
      - mysql

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: webp_converter
    volumes:
      - mysql_data:/var/lib/mysql
      - ./schema.sql:/docker-entrypoint-initdb.d/schema.sql

  worker:
    build: .
    command: |
      bash -c "while true; do php /var/www/html/worker.php; sleep 30; done"
    volumes:
      - ./storage:/var/www/html/storage
      - ./config:/var/www/html/config
    depends_on:
      - mysql

volumes:
  mysql_data:
```

**Executar:**
```bash
docker-compose up -d
```

## ☁️ Deploy em Cloud

### Oracle Cloud (Free Tier)

1. **Criar VM:**
   - Shape: VM.Standard.E2.1.Micro (Free)
   - OS: Ubuntu 22.04
   - Public IP: Sim

2. **SSH e instalar:**
```bash
ssh ubuntu@seu-ip

# Instalar stack
sudo apt update
sudo apt install -y apache2 php8.1 php8.1-{mysql,gd,imagick,curl,xml,mbstring} mysql-server

# Configurar Apache
sudo a2enmod rewrite
sudo systemctl restart apache2

# Clonar projeto
cd /var/www/html
sudo git clone https://github.com/danilostorm/webp-converter-api.git api
sudo chown -R www-data:www-data api/storage

# MySQL
sudo mysql
CREATE DATABASE webp_converter;
CREATE USER 'webp'@'localhost' IDENTIFIED BY 'senha-forte';
GRANT ALL ON webp_converter.* TO 'webp'@'localhost';
FLUSH PRIVILEGES;
exit;
```

3. **Acessar instalador:**
```
http://seu-ip/api/install.php
```

### DigitalOcean / Vultr / AWS

Similar ao Oracle Cloud, usando droplet/instance Ubuntu.

## 🔒 Checklist Pós-Deploy

- [ ] `install.php` deletado
- [ ] Permissões corretas em `storage/`
- [ ] Banco de dados criado e populado
- [ ] API Key salva em local seguro
- [ ] Cron job configurado
- [ ] Teste: `curl -H "X-API-Key: sua-key" https://seu-dominio.com/api/v1/health`
- [ ] SSL/HTTPS configurado (Let's Encrypt)
- [ ] Firewall configurado (apenas 80/443)
- [ ] Backup configurado (banco + storage)

## 🔧 Troubleshooting Pós-Deploy

### Erro 500
```bash
# Verificar logs Apache
sudo tail -f /var/log/apache2/error.log

# Verificar logs da aplicação
tail -f storage/logs/app.log
```

### Extensões faltando
```bash
php -m | grep -E 'imagick|gd|pdo_mysql'

# Se faltar, instalar:
sudo apt install php8.1-imagick php8.1-gd php8.1-mysql
sudo systemctl restart apache2
```

### Worker não processa
```bash
# Testar manualmente
cd /path/to/api
php worker.php

# Ver cron logs
grep CRON /var/log/syslog
```

### Permissões
```bash
# Ajustar owner
sudo chown -R www-data:www-data /var/www/html/api

# Ajustar permissões
find /var/www/html/api -type d -exec chmod 755 {} \;
find /var/www/html/api -type f -exec chmod 644 {} \;
chmod -R 755 /var/www/html/api/storage
```

## 📊 Monitoramento

### Script de monitoração simples

```bash
#!/bin/bash
# monitor.sh

API_URL="https://seu-dominio.com/api/v1/health"
API_KEY="sua-key"

response=$(curl -s -H "X-API-Key: $API_KEY" $API_URL)

if echo "$response" | grep -q '"status":"ok"'; then
    echo "[OK] API is running"
else
    echo "[ERROR] API is down!"
    # Enviar alerta (email, telegram, etc)
fi
```

Adicionar ao cron:
```bash
*/5 * * * * /path/to/monitor.sh
```

## 🔄 Updates

### Atualizar versão

```bash
cd /path/to/api

# Backup
cp -r . ../api-backup-$(date +%Y%m%d)

# Update
git pull origin main

# Verificar migrações de banco (se houver)
# php migrate.php

# Testar
curl -H "X-API-Key: key" http://localhost/api/v1/health
```

---

**Precisa de ajuda?** Abra uma issue no [GitHub](https://github.com/danilostorm/webp-converter-api/issues)
