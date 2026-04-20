# Release Simulador

Este paquete contiene una copia autocontenida del simulador.

## Archivos oficiales
- index.php
- npcs.php
- esim.php

## Recursos locales (mismo nivel)
- .env
- .env.example
- countries.json
- regions.json
- npc_statistics_cache.json
- owned_companies_cache.json
- tmp/

## Configuracion
1. Copia `.env.example` a `.env`.
2. Completa tus credenciales en `.env`.
3. Ejecuta `index.php` normalmente.

## Nota de rutas
Los scripts usan rutas basadas en `__DIR__`, por lo que todos los JSON/cache/cookies quedan en esta misma carpeta `simulador` del release.
