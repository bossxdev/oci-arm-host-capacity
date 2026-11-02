# Repository Guidelines

## Project Overview

สคริปต์ PHP CLI สำหรับตรวจ OCI และลองสร้าง compute instance เมื่อมี capacity; เดิมออกแบบให้ cron เรียกซ้ำ. `Dockerfile`/`compose.yaml` เพิ่ม container แบบรันครั้งเดียวที่ใช้ `DRY_RUN=true` โดยปริยาย; ยังไม่มี worker loop. `LaunchState` เก็บ attempt ใน SQLite และล็อกกระบวนการก่อน launch จริง.

## Architecture & Data Flow

`index.php` โหลด Composer และ `.env` → ตรวจ config/home region → inventory A1 ทุก compartment พร้อม pagination → ตรวจ AD และ `checkLaunchResources()` → dry-run หยุดก่อน POST; เมื่อเปิด provisioning จะบันทึก attempt ก่อน `createInstance()` และใช้ `opc-retry-token`. `InstanceMonitor` GET ด้วย OCID หลัง launch/restart: เฉพาะ `RUNNING` จึงบันทึก success และแจ้ง Telegram. `PROVISIONING`/`STARTING` ออกแล้วรอตรวจรอบถัดไป; state อื่นหยุดให้ตรวจสอบ. Preflight ไม่พิสูจน์ป้าย Always Free หรือ free storage quota.

## Key Directories

- `src/`: OCI API/config, HTTP client, cache, rate-limit waiter และ exceptions.
- `src/Interfaces/`, `src/Notification/`: contracts สำหรับ cache/waiter/notifier และ Telegram notifier.
- `tests/Hitrov/Test/`, `tests/resources/`: PHPUnit tests และ env fixtures; บางชุดยิง OCI จริง.
- `.github/workflows/`: CI ทดสอบบน push/PR; schedule ใน workflow ปัจจุบันถูกคอมเมนต์.
- `images/`: ภาพประกอบ `README.md`; ไม่มี `scripts/` หรือ `docs/`.

## Development Commands

- `composer install`; `cp .env.example .env` แล้วกำหนด OCI credentials, key, subnet/image, shape และ SSH public key ก่อนรัน.
- `php ./index.php` หรือ `php index.php .env.my_acc1`: `.env.example` ตั้ง `DRY_RUN=true`; เมื่อเปลี่ยนเป็น `false` อาจส่งคำขอสร้าง instance จริง. Container ต้องตั้ง `OCI_ENABLE_PROVISIONING=true` ร่วมด้วย.
- `vendor/bin/phpunit`: live OCI tests (`OciApiTest`, `OciApiWithEnvFileTest`, `BootVolumeIdTest`, `BootVolumeSizeTest`) skip อัตโนมัติเว้นแต่ตั้ง `OCI_INTEGRATION_TESTS=1` พร้อมบัญชีทิ้งที่ยอมรับการสร้าง resource ได้.
- CI (`.github/workflows/tests.yml`): PHP 8.3, `composer install`, `vendor/bin/phpunit` (live tests self-skip โดย `OCI_INTEGRATION_TESTS=1`) และ smoke scripts ทั้งห้า โดยไม่มี OCI secrets ใน CI. `phpunit.xml.dist` รัน `tests/Hitrov/Test` ทั้งหมด.

## Code Conventions & Common Patterns

- Composer PSR-4: namespace `Hitrov\` ตรงกับ `src/`; tests ใช้ `Hitrov\Test\` ตรงกับ `tests/Hitrov/Test/`. รักษาชื่อคลาส/ไฟล์ตามโครงสร้างเดิม.
- ค่าตั้งต้นอ่านจาก `getenv()` ผ่าน `OciConfig`; `index.php` ประกอบ dependencies และเปิด cache/waiter ตาม env flags. ใช้ interfaces ที่มีอยู่แทนการสร้าง wiring อีกชุด.
- `HttpClient` แปลง HTTP non-2xx เป็น `ApiCallException`; `OciApi::call()` บันทึก 429 และใช้ `TooManyRequestsWaiterException` หยุดทุก OCI request จนพ้น cooldown (รวม GET/dry-run). `index.php` ลบ pending เฉพาะ 429 ที่ยืนยันแล้วหรือ capacity rejection ที่ตรงรหัส/ข้อความ; timeout/5xx อื่นคง pending.
- `$notifyFailure` ใน `index.php` ส่งข้อความ Telegram (best-effort) เมื่อ: ผล launch กำกวม, backoff คงอยู่/ตั้งใหม่, 429, launch ล้มเหลว, transport error และ response identity ไม่ตรง.
- ไม่มี in-process loop; `capacity_backoff` ใน SQLite เก็บตาราง 5→10→20→40→นาที (เพดาน 60) หลังไม่มี capacity ทุก AD. รอบ provisioning ที่เปิดใช้จะออกก่อนยิง launch เมื่อยังถือ backoff; launch สำเร็จเคลียร์ตาราง. cache domain, cooldown และ state อยู่ใน working directory.

## Important Files

- `index.php`: entry point และลูป provisioning.
- `src/OciApi.php`: inventory, domains, launch และ OCI request signing.
- `src/LaunchState.php`, `src/InstanceMonitor.php`: SQLite attempt/OCID/notification acknowledgment, `flock`, GET lifecycle; Telegram ล้มเหลวแล้วคง pending. `OciApi::createInstance()` ส่ง `opc-retry-token`.
- `src/OciConfig.php`, `.env.example`: ตัวเลือกและเงื่อนไข image/boot volume; boot volume ID ต้องระบุ AD และใช้ร่วมกับขนาด boot volume ไม่ได้.
- `src/HttpClient.php`, `src/TooManyRequestsWaiter.php`, `src/FileCache.php`, `src/Notification/Telegram.php`: transport, cooldown, cache, notification.
- `composer.json`, `composer.lock`, `.github/workflows/tests.yml`, `README.md`: dependencies, CI และวิธีติดตั้ง/cron.
- `Dockerfile`, `compose.yaml`, `.dockerignore`: PHP 8.3 CLI, Compose secrets, named volume, non-root และ gate `DRY_RUN`/`OCI_ENABLE_PROVISIONING`.

## Runtime/Tooling Preferences

`composer.json` กำหนด PHP `>=7.0 <9.0`, `ext-curl`, `ext-json`; launch state ต้องมี `pdo_sqlite`. ใช้ Composer, phpdotenv, OCI signer และ PHPUnit 9; CI ใช้ PHP 8.3. ค่าเริ่มต้น A1 คือ 2 OCPU/12 GB; preflight ยังไม่ยืนยัน storage quota หรือป้าย Always Free. Telegram อาจส่งซ้ำถ้า crash หลัง API ตอบแต่ก่อนบันทึก acknowledgment. ห้ามเปิด restart/launch อัตโนมัติ; อย่า commit `.env`/private key/token.

## Testing & QA

Unit tests เช่น `OciConfigTest.php`, `FileCacheTest.php`, `TooManyRequestsWaiterTest.php` และ `OciApiCreateInstanceWithWaiterTest.php` ครอบคลุม config/cache/cooldown ด้วย mock หรือไฟล์ใน working directory. `OciApiTest.php`, `OciApiWithEnvFileTest.php`, `BootVolumeIdTest.php` และ `BootVolumeSizeTest.php` เรียก OCI จริง; เคส `createInstance()` คาด error แต่สามารถสร้าง resource ได้เมื่อบัญชี/โควตาเปลี่ยน. ทดสอบเฉพาะไฟล์ที่ปลอดภัยเมื่อไม่มี tenancy ทดสอบ; ระวัง test ที่เขียน/ลบ `oci_cache.json` และ `too_many_requests_waiter.txt`. ไม่มีเกณฑ์ coverage ระบุไว้.
