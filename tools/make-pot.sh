#!/usr/bin/env bash

wp i18n make-pot . languages/fpe.pot \
  --include="src" \
  --slug="fpe" \
  --headers='{"Report-Msgid-Bugs-To":"https://github.com/hirasso/fpe/","POT-Creation-Date":""}'
