# Review findings — #778

No open findings. Round 1 verified the umask resets in GenericRuntime and Worker::daemonize (so entrypoint pinning is genuinely ineffective), that the bootstrap pin + post-start hardening yields non-world-writable `var/cache`/`var/log` under `umask 0000`, that the hardening is idempotent and survives the daemon's lazy cache writes, and that the structural guard test + FAQ-040 record the mechanism.
