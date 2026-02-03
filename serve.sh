#!/bin/bash
echo "Iniciando servidor PHP en http://localhost:8000"
echo ""
echo "Presiona Ctrl+C para detener el servidor"
echo ""
cd public
php -S localhost:8000
