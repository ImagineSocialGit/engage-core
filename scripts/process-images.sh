#!/usr/bin/env bash
set -e

RAW_DIR="resources/images/raw"
OUT_DIR="public/images/processed"
MANIFEST_PATH="resources/images/manifest.json"

SIZES=(320 640 960 1280 1600)

find "$RAW_DIR" -type f -print0 | while IFS= read -r -d '' file; do

  filename=$(basename "$file")
  name="${filename%.*}"

  relative_path="${file#$RAW_DIR/}"
  relative_dir=$(dirname "$relative_path")
  output_dir="$OUT_DIR/$relative_dir/$name"

  if [ "$relative_dir" = "." ]; then
    manifest_path="$name"
    output_dir="$OUT_DIR/$name"
  else
    manifest_path="$relative_dir/$name"
  fi

  mkdir -p "$output_dir"

  echo "Processing $file"

  for size in "${SIZES[@]}"; do

    sharp -i "$file" \
      -o "$output_dir/$size.avif" \
      -f avif \
      -- resize "$size"

    sharp -i "$file" \
      -o "$output_dir/$size.webp" \
      -f webp \
      -- resize "$size"

  done

  sharp -i "$file" \
    -o "$output_dir/placeholder.webp" \
    -f webp \
    -- resize 40 \
    -- blur 10

  printf "Add manifest entry for %s? [y/N] " "$manifest_path" > /dev/tty
  read -r add_manifest < /dev/tty

  case "$add_manifest" in
    [yY]|[yY][eE][sS])
      printf "Manifest key: " > /dev/tty
      read -r manifest_key < /dev/tty

      if [ -n "$manifest_key" ]; then
        php -r '
          $manifestPath = $argv[1];
          $key = $argv[2];
          $value = $argv[3];

          if (! file_exists($manifestPath)) {
              file_put_contents($manifestPath, "{}\n");
          }

          $contents = file_get_contents($manifestPath);
          $data = json_decode($contents, true);

          if (! is_array($data)) {
              fwrite(STDERR, "manifest.json is invalid.\n");
              exit(1);
          }

          $data[$key] = $value;
          ksort($data);

          $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

          if ($json === false) {
              fwrite(STDERR, "Failed to encode manifest.json.\n");
              exit(1);
          }

          file_put_contents($manifestPath, $json . PHP_EOL);
        ' "$MANIFEST_PATH" "$manifest_key" "$manifest_path"

        echo "Added manifest entry: $manifest_key => $manifest_path"
      fi
      ;;
  esac

done