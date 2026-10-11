#!/usr/bin/env bash
# Shared metadata assertions; never print container env, credentials, or full inspect.
list_unrelated() {
  local id name project started rows
  rows=$(docker ps --format '{{.ID}}|{{.Names}}|{{.Label "com.docker.compose.project"}}') || fail 'Unable to snapshot running containers.'
  while IFS='|' read -r id name project; do
    [ -n "$id" ] || continue
    [ "$project" = "$PROJECT" ] && continue
    started=$(docker inspect --format '{{.State.StartedAt}}' "$id") || fail 'Unable to snapshot unrelated StartedAt.'
    printf '%s|%s|%s\n' "$id" "$name" "$started"
  done <<< "$rows"
}

assert_unrelated() {
  local after entry
  after=$(list_unrelated)
  echo '>>> Unrelated containers after (ID/name/StartedAt)'
  printf '%s\n' "$after"
  while IFS= read -r entry; do
    [ -n "$entry" ] || continue
    printf '%s\n' "$after" | grep -Fxq "$entry" || fail 'Unrelated container disappeared or restarted.'
  done <<< "$1"
}

list_staging_volumes() {
  local volume
  for volume in rt05-staging_redis-data rt05-staging_api-storage rt05-staging_apk-downloads; do
    docker volume inspect --format '{{.Name}}|{{.CreatedAt}}' "$volume" || fail 'Existing staging volume missing; refusing an empty replacement.'
  done
}

assert_staging_volumes() {
  local after
  after=$(list_staging_volumes)
  [ "$after" = "$1" ] || fail 'Staging volume identity changed.'
  echo '>>> Preserved staging volume names/CreatedAt'
  printf '%s\n' "$after"
  local service expected mounted container
  for service in redis api gateway; do
    case "$service" in
      redis) expected=rt05-staging_redis-data ;;
      api) expected=rt05-staging_api-storage ;;
      gateway) expected=rt05-staging_apk-downloads ;;
    esac
    container=$("${DC[@]}" ps -q "$service")
    [ -n "$container" ] || fail "Missing staging service $service"
    mounted=$(docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{println .Name}}{{end}}{{end}}' "$container")
    printf '%s\n' "$mounted" | grep -Fxq "$expected" || fail "Wrong staging volume for $service"
  done
}

assert_staging_images() {
  local service expected container actual
  for service in api web worker-default worker-media worker-exports scheduler; do
    expected="rt05-api:$RT05_IMAGE_TAG"
    [ "$service" != web ] || expected="rt05-web:$RT05_IMAGE_TAG"
    container=$("${DC[@]}" ps -q "$service")
    [ -n "$container" ] || fail "Missing staging service $service"
    actual=$(docker inspect --format '{{.Config.Image}}' "$container")
    [ "$actual" = "$expected" ] || fail "Image SHA mismatch for $service"
    printf '%s image=%s\n' "$service" "$actual"
  done
}
