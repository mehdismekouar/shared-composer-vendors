# Shared Composer vendors

Keeps **one copy of each package version** for all your Composer-based PHP projects **in a shared folder**, and lets every project's `vendor/` point to it.

Built for shared hosting with an **inode limit** (a cap on how many files and folders your account may have) or any environment with lots of Composer-based projects wanting to save space. A Laravel project's `vendor/` for example holds 13,000 to 20,000 files and around 150 MB of space. With 10 projects on nearly the same packages, that is more than 150,000 inodes and 1.5 GB of space spent on nearly identical copies. With this tool, each project's `vendor/` shrinks to a few hundred links, and the packages exist once.

It should run as part of each project's deploy, in place of `composer install`.

## The idea in one picture

```
Without the tool                              With the tool
site-a/vendor/laravel/framework/ 1,600 files  site-a/vendor/laravel/framework → link
site-b/vendor/laravel/framework/ 1,600 files  site-b/vendor/laravel/framework → link
                                              shared-vendors/vendors/laravel/framework_13.33.0/ 1,600 files, once
```

A few words used in this document:

- **Shared folder**: the folder that holds the single copy of each package version. It is `vendors/`, next to `setup.php`.
- **Link**: a tiny entry that points to a folder somewhere else, like a shortcut. PHP reads through it as if the files were really there. On Linux it is a *symbolic link*, on Windows a *junction*.
- **Hard link**: a second name for the same file. It takes no extra space and doesn't count as an extra file.

## How a deploy works

Every deploy goes through five steps. Your site keeps running during all of them.

1. **Build a new `vendor/` next to the live one.** The tool runs `composer install` into a temporary folder called `vendor.next`. The site keeps using the current `vendor/` meanwhile.
2. **Swap the folders.** The current `vendor/` is renamed to `vendor.old`, and `vendor.next` becomes `vendor/`. Renaming is instant, so the site never sees a half-installed `vendor/`. If anything failed before this step, nothing has changed.
3. **Run your project's Composer scripts**, for example Laravel's `package:discover`. If a script fails, the previous `vendor/` is put back.
4. **Share the packages.** Each package is compared, file by file, with the original zip Composer downloaded (Composer keeps these zips in its cache folder).
   - Identical: the package folder is replaced by a link to the shared copy. The first project using a version puts it into the shared folder.
   - Changed after download (by a patch, a Composer plugin or one of your scripts): the project keeps its own copy, unless another project made exactly the same change. Then both use one shared copy of the changed version.
5. **Clean up.** The previous `vendor/` is deleted and a summary is printed.

## Requirements

- **PHP 8.0 or newer**, used from the command line (the same PHP your deploy uses).
- **Composer 2**, with its download cache turned on, which is the default. The cache is the folder where Composer keeps the zip of every package it downloads (usually `~/.cache/composer` on Linux). The tool compares packages with those zips, so the cache must stay on disk between deploys.
- **On Linux servers**, the shared folder and your projects should be on the same disk, which is almost always the case on shared hosting. Hard links only work on the same disk; otherwise the tool copies those few files, which costs a little more space.
- **git**, only if a project uses the composer-patches plugin version 2 (it applies patches with git).
- **Windows** works too. It uses junctions instead of symbolic links, and copies instead of hard links. It was tested on a Windows development machine, not yet on a Windows production server, but nothing in it depends on the difference, so it should work there too.

## Installation

Put this repository in the folder above your projects. The shared folder `vendors/` is created inside it on the first run.

```bash
cd ~
git clone https://github.com/mehdismekouar/shared-composer-vendors.git shared-vendors
```

```
~/shared-vendors/            this repository
~/shared-vendors/vendors/    the shared folder, created on the first run
~/domains/site-a/            a project
~/domains/site-b/            another project
```

## Usage

In each project's deploy script, replace `composer install` with a call to `setup.php`. For example:

```bash
# Before                              # After
cd ~/domains/site-a                   cd ~/domains/site-a
git pull                              git pull
composer install --no-dev             php ~/shared-vendors/setup.php
php artisan migrate --force           php artisan migrate --force
```

| Option | Meaning |
|---|---|
| `PATH` or `-p PATH` | The project folder. Default: the current folder. |
| `--dry` | Preview: shows what would happen, without changing anything. |
| `--dev` | Also install dev packages (the ones under `require-dev` in `composer.json`, such as test tools). By default they are left out, like `composer install --no-dev`. |
| `--wait SECONDS` | Only one project can deploy at a time. If another one is deploying, wait this long before giving up. Default: 600 (10 minutes). |
| `--composer COMMAND` | How to start Composer, if `composer` alone doesn't work on your server. Example: `--composer "php ~/composer.phar"`. |
| `-- OPTIONS` | Anything after `--` is passed to `composer install`. Example: `-- --optimize-autoloader`. |

To use a specific PHP version, call its binary directly, for example `/opt/cpanel/ea-php83/root/usr/bin/php ~/shared-vendors/setup.php`. On Windows: `php C:\tools\shared-vendors\setup.php C:\projects\site-a --dev`.

**Exit code** (the number a command returns, which your deploy script can check):

- `0`: the site is deployed.
- `1`: the deploy failed, and either nothing was changed or everything was put back as it was.

A package that could not be linked keeps its own copy and is shown in red, but the deploy still counts as successful, because the site works.

## Keeping a package out of the shared folder

List it in the project's `composer.json`. Wildcards are allowed:

```json
"extra": {
    "non-shared-vendors": ["some/package", "some-vendor/*"]
}
```

Most packages never need this. The main reason is a package that writes files into its own folder while your site runs, in a way the tool can't detect (see "Packages that need a bit of their own space" below). The end of each deploy lists packages that look like that. For each one, you have two choices:

- **Better:** set the package's temp or cache folder to your project's `storage/` folder in its configuration. The package stays shared.
- **Simpler:** add it to `non-shared-vendors`. The project keeps its own full copy.

## What gets shared

| The package... | Result |
|---|---|
| is identical to what Composer downloaded | Shared: one copy per version for all projects. |
| was changed after download (patch, plugin, script) | The project keeps its own copy, until another project makes exactly the same change. Then they share it. |
| is listed in `non-shared-vendors` | The project keeps its own copy. |
| was installed from a git repository or a local folder | The project keeps its own copy (its content can change without its version changing). |
| can't be compared (its zip is missing from Composer's cache) | Shared only if identical to the copy already in the shared folder; otherwise the project keeps its own copy. |
| is one of Composer's own packages (`composer/*`) | Stays in `vendor/composer`, next to the autoloader. |

Why a changed package waits for a second project: with a single project, sharing saves nothing. And some changes are different on every deploy (a generated file with a date in it, for example). Storing those would add a full new copy to the shared folder each time.

## Packages that need a bit of their own space

A few packages don't work well behind a single link:

1. **Files that look for other files by walking up folders from where they are.** For example, the command-line tools in `vendor/bin` (like `php-parse`) look for `vendor/autoload.php` "three folders up from me". Through a link, PHP sees the file where it really is, in the shared folder, so "three folders up" lands in the wrong place and the tool fails.
2. **Packages that write files into their own folder while your site runs.** For example, mPDF writes temporary files into its `tmp/` folder. The shared folder is read-only (to protect it, see Safety), so those writes would fail.

For these packages, the project gets a real folder instead of one link:

- the files that look around sit at the project's own address, as hard links (so they cost nothing);
- the folders the package writes into are real, writable folders that belong to this project only;
- everything else still points to the shared copy.

The package works as usual, and it costs a handful of extra files (usually 3 to 20). For mPDF it looks like this:

```
vendor/mpdf/mpdf/            a real folder
├── data/, ttfonts/          links to the shared copy (most of the package)
├── src/Config/...           real folders with hard-linked files (they decide where the temp folder is)
└── tmp/                     a real, writable folder: mPDF's temp files stay in this project
```

The tool finds these files by reading the package's code when a version is added to the shared folder. When it finds a write it can't locate (a path built while the site runs), it warns you instead (see "Keeping a package out of the shared folder").

## Reading the output

```
[4/5] Linking packages to the shared folder
  [LINK]  laravel/framework v13.33.0
  [NEW]   filament/filament v3.3.43
  [LINK]  mpdf/mpdf v8.3.1 - has its own writable folder in this project: tmp
  [LOCAL] psr/log 3.0.2 - patched by composer-patches (changed: src/LoggerInterface.php); it will be shared as soon as another project makes the exact same change
  [LINK]  psr/simple-cache 3.0.0 - shared changed version b44ebce2, changed after download (changed: src/CacheInterface.php)
  [LOCAL] ramsey/collection 2.1.1 - installed from a git repository, so its files can change without a new version
```

| Label | Meaning |
|---|---|
| `[LINK]` | Linked to a copy that was already in the shared folder. |
| `[NEW]` | Added to the shared folder by this deploy, then linked. |
| `[LOCAL]` | The project keeps its own copy. The reason follows on the same line. |
| `[FAIL]` | Could not be linked. The project keeps its own copy. |

Two more details can appear on a line:

- **"has its own writable folder in this project"**: the package got a folder of its own, as described above.
- **"shared changed version"**: a changed package that is shared by several projects which made exactly the same change. The code after it (`b44ebce2`) identifies that version of the change.

After the list come the **warnings** (packages to configure or to keep out of the shared folder, with the file and line that triggered it), then a **summary**:

```
Summary: 90 packages
  Linked to the shared folder:      85
  Added to the shared folder:       0
  Kept their own copy:              5
  With some files of their own:     8 (tools, writable folders)
  Files shared instead of copied:   7,247
```

"With some files of their own" counts the packages that got a real folder instead of a single link, as described in "Packages that need a bit of their own space".

## Composer scripts and plugins

Composer scripts are commands you list under `"scripts"` in `composer.json`. Composer runs them at certain moments, called events (`post-install-cmd` runs after an install, for example). The tool runs your scripts at the same moments as a normal `composer install` would:

```
pre-install-cmd → pre-autoload-dump → build (plugins and per-package scripts run here) → swap → post-autoload-dump → post-install-cmd
```

- **Plugins** (packages that extend Composer, like composer-patches) run during the build, as usual.
- **Per-package scripts** (like `post-package-install`, which runs after each package is installed) also run during the build. They work on the new `vendor.next` folder.

New Laravel projects include a `pre-package-uninstall` script (`Illuminate\Foundation\ComposerScripts::prePackageUninstall`). The tool ignores it silently: it only does something when a package is uninstalled on a development machine, and this tool never uninstalls packages.

## Safety

- **No half-installed `vendor/`**: the new one is built next to the live one and swapped in by renaming.
- **Failed scripts are undone**: if one of your scripts fails, the previous `vendor/` is put back.
- **One deploy at a time**: other deploys wait for their turn. If a deploy crashes, the next one can still start.
- **Only verified packages are shared**: a package goes into the shared folder only if it is identical to what Composer downloaded, or identical, file by file, to another project's changed copy.
- **The shared folder is read-only**: packages in it are never overwritten, so one project can't change what the other projects use. This also blocks accidents, such as deleting a shared package through a link. On Windows, only files can be made read-only (see Known limits).
- **No half-copied packages**: a new version is copied under a temporary name, and renamed only when complete.

## What the shared folder looks like

```
shared-vendors/
├── setup.php
└── vendors/                           the shared folder
    ├── .lock, .lock-owner             lets only one deploy run at a time
    ├── laravel/
    │   ├── framework_13.33.0/         the package (read-only)
    │   └── framework_13.33.0.json     notes about it: file checksums, special files, warnings
    └── psr/
        ├── log_3.0.2/                 the original package
        ├── log_3.0.2-edit-b44ebce2/   a changed version, shared by projects that made the same change
        └── log_3.0.2.edits.json       changes seen so far, and which projects made them
```

Folders are named `<package>_<version>`. Development versions (like `dev-main`) also include the commit they were built from, because a branch keeps changing.

## Known limits

- **Scripts for package updates and uninstalls never run** (`pre/post-package-update`, `pre/post-package-uninstall`). Every deploy installs all packages fresh into a new folder, so Composer only sees installs. Put that work in `post-package-install`.
- **Per-package scripts written as commands see the old packages.** A script like `@php artisan ...` runs while the old `vendor/` is still the live one. Scripts written as PHP class methods (`App\Composer\Hooks::method`) receive the new packages.
- **`pre-autoload-dump` runs before the build**, not after the packages are installed as in a normal install.
- **A dry run doesn't run your scripts**, because they could change your site (files, caches, even the database). It lists the scripts it skipped, and can't show changes they would make to packages.
- **For about 2 minutes after a deploy that changes a package's version, some requests may still use the old version's files.** PHP remembers for 120 seconds where each file path leads (its "realpath cache"), to avoid asking the disk again. The old version is still in the shared folder, so PHP keeps opening it until it forgets. If your host lets you restart PHP (often a button in the control panel), do it after deploying.
- **The warnings are educated guesses.** They come from reading the code, not running it, so they can miss cases or flag harmless code.
- **On Windows, folders can't be made read-only, only files:**
  - Editing a shared file is refused, but new files can still be created inside a shared folder. Packages that need to write get their own folders, so this only matters for the packages listed in the warnings.
  - In Git Bash, `rm -rf vendor/some/package/` **with a slash at the end** (Tab completion adds it) deletes the shared copy, for every project. Without the slash it's safe, and so are `rmdir /s`, `del /s` and PowerShell's `Remove-Item -Recurse` (all tested).
  - Windows can't rename a folder while another program has a file in it open. The tool retries for up to 10 seconds, but some programs hold files longer: an editor indexing your project (VS Code's PHP extensions read everything in `vendor/`), an antivirus, or a web server busy serving the site. The deploy then stops without changing anything: run it again, or exclude the `vendor*` folders and the shared folder from your editor's indexing.
- **Old versions are never deleted automatically.** When no project uses a version anymore, it stays in the shared folder (see "Deleting a version by hand").

## Troubleshooting

**"Composer's download cache is not available"**: Composer's cache is turned off or points to a temporary folder. Turn it back on: remove the `COMPOSER_CACHE_DIR` environment variable, or point it to a folder that is kept.

**"Waiting: site-b is deploying..."**: another project is deploying. This one continues when the other finishes, or gives up after `--wait` seconds.

**A package stays `[LOCAL]`**: the reason is on its line. A changed package becomes shared as soon as another project makes exactly the same change.

**Errors for a minute or two right after a deploy**: see the "realpath cache" point in Known limits.

**Don't run `vendor/bin/carbon` on a server**: when one of its optional packages is missing, it runs `composer require` by itself, which changes your project.

**Deleting a version by hand**: first check that no project still links to it, then make it writable (shared packages are read-only) and delete it:

```bash
# check for project links to the package
find ~/domains -path '*/vendor/*' -lname '*framework_13.33.0*' | head

# make the shared package folder writable then remove it
chmod -R u+w ~/shared-vendors/vendors/laravel/framework_13.33.0 && rm -rf ~/shared-vendors/vendors/laravel/framework_13.33.0*
```

## Credits

- [Mehdi Mekouar](https://github.com/mehdismekouar)
- Read the story behind it: [Shared Composer Vendors: beating the inode limit on shared hosting](https://mehdimekouar.com/articles/shared-composer-vendors-shared-hosting)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
