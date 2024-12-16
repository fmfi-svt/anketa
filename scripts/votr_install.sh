#!/bin/bash

set -e
cd "`dirname "$0"`/../vendor/svt/votr"
uv sync --no-group votrfront
