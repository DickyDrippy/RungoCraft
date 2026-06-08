@echo off
cd /d %~dp0\..
if not exist ssl mkdir ssl
openssl req -x509 -nodes -days 825 -newkey rsa:2048 ^
  -keyout ssl\rungocraft-local.key ^
  -out ssl\rungocraft-local.crt ^
  -config ssl\openssl-localhost.cnf
echo Local SSL certificate created: ssl\rungocraft-local.crt
echo Private key: ssl\rungocraft-local.key
