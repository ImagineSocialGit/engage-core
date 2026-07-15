#!/usr/bin/env bash

set -euo pipefail

CORE_ENV=".env"

if [[ ! -f "$CORE_ENV" ]]; then
  echo "Core .env file not found."
  exit 1
fi

set -a
source "$CORE_ENV"
set +a

if [[ -z "${CLIENT_KEY:-}" ]]; then
  echo "CLIENT_KEY is not set in core .env."
  exit 1
fi

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  echo "CLIENT_KEY contains invalid characters."
  exit 1
fi

CLIENT_DIR="client/$CLIENT_KEY"
RAW_DIR="$CLIENT_DIR/resources/images/raw"
OUT_DIR="public/images/processed/$CLIENT_KEY"
MANIFEST_PATH="$CLIENT_DIR/resources/images/manifest.json"

if [[ ! -d "$RAW_DIR" ]]; then
  echo "Client raw image directory not found: $RAW_DIR"
  exit 1
fi

SIZES=(320 640 960 1280 1600)

find "$RAW_DIR" -type f -print0 | while IFS= read -r -d '' file; do
  filename=$(basename "$file")
  name="${filename%.*}"

  relative_path="${file#$RAW_DIR/}"
  relative_dir=$(dirname "$relative_path")
  output_dir="$OUT_DIR/$relative_dir/$name"

  if [[ "$relative_dir" = "." ]]; then
    manifest_path="$name"
    output_dir="$OUT_DIR/$name"
  else
    manifest_path="$relative_dir/$name"
  fi

  mkdir -p "$output_dir"

  echo "Processing $file"

  printf "Max width for %s? [320/640/960/1280/1600, default 1600] " "$manifest_path" > /dev/tty
  read -r max_width < /dev/tty

  max_width="${max_width:-1600}"

  case "$max_width" in
    320|640|960|1280|1600) ;;
    *)
      echo "Invalid max width: $max_width"
      exit 1
      ;;
  esac

  processed_sizes=()

  for size in "${SIZES[@]}"; do
    if [[ "$size" -le "$max_width" ]]; then
      processed_sizes+=("$size")

      if [[ -f "$output_dir/$size.webp" ]] \
        && [[ -f "$output_dir/$size.avif" ]] \
        && [[ "$file" -ot "$output_dir/$size.webp" ]] \
        && [[ "$file" -ot "$output_dir/$size.avif" ]]; then
        continue
      fi

      sharp -i "$file" \
        -o "$output_dir/$size.avif" \
        -f avif \
        -- resize "$size"

      sharp -i "$file" \
        -o "$output_dir/$size.webp" \
        -f webp \
        -- resize "$size"
    fi
  done

  if [[ ! -f "$output_dir/placeholder.webp" ]] || [[ "$file" -nt "$output_dir/placeholder.webp" ]]; then
    sharp -i "$file" \
      -o "$output_dir/placeholder.webp" \
      -f webp \
      -- resize 40 \
      -- blur 10
  fi

  printf "Add manifest entry for %s? [y/N] " "$manifest_path" > /dev/tty
  read -r add_manifest < /dev/tty

  case "$add_manifest" in
    [yY]|[yY][eE][sS])
      printf "Manifest key: " > /dev/tty
      read -r manifest_key < /dev/tty

      if [[ -n "$manifest_key" ]]; then
        sizes_csv=$(IFS=,; echo "${processed_sizes[*]}")

        mkdir -p "$(dirname "$MANIFEST_PATH")"

        php -r '
          $manifestPath = $argv[1];
          $key = $argv[2];
          $path = $argv[3];
          $maxWidth = (int) $argv[4];
          $sizes = array_map("intval", explode(",", $argv[5]));

          if (! file_exists($manifestPath)) {
              file_put_contents($manifestPath, "{}\n");
          }

          $contents = file_get_contents($manifestPath);
          $data = json_decode($contents, true);

          if (! is_array($data)) {
              fwrite(STDERR, "manifest.json is invalid.\n");
              exit(1);
          }

          $data[$key] = [
              "path" => $path,
              "max_width" => $maxWidth,
              "sizes" => $sizes,
              "formats" => ["avif", "webp"],
              "placeholder" => $path . "/placeholder.webp",
          ];

          ksort($data);

          $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

          if ($json === false) {
              fwrite(STDERR, "Failed to encode manifest.json.\n");
              exit(1);
          }

          file_put_contents($manifestPath, $json . PHP_EOL);
        ' "$MANIFEST_PATH" "$manifest_key" "$manifest_path" "$max_width" "$sizes_csv"

        echo "Added manifest entry: $manifest_key => $manifest_path"
      fi
      ;;
  esac
done

echo
echo "Processed images for client: $CLIENT_KEY"
echo "Output: $OUT_DIR"
echo "Manifest: $MANIFEST_PATH"
