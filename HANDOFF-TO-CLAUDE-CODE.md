# ส่งไม้ต่อให้ Claude Code

เอกสารนี้สรุปงานที่ทำค้างไว้จากเซสชัน Cowork (ทำงานผ่าน cloud sandbox ที่ **รันเทส/รัน build ไม่ได้**)
งานทั้งหมด **เขียนโค้ดเสร็จแล้วและอยู่ใน working tree** แต่ **ยังไม่เคยถูกรันหรือทดสอบจริงแม้แต่ครั้งเดียว**

โปรเจกต์: Laravel 13 + Inertia + React 19 + TypeScript (ระบบจัดการออเดอร์เสื้อผ้า)
สาขา: `main` — การเปลี่ยนแปลงทั้งหมดยัง **uncommitted**

---

## 🔴 สิ่งที่ต้องทำก่อนอื่น (เรียงตามลำดับความสำคัญ)

### 1. รัน migration ที่ค้างอยู่ 2 ตัว
```bash
php artisan migrate
```
- `2026_08_26_000001_split_shirt_color_catalog_storage_keys.php` — แยก storage key ของสีเสื้อ (ฟีเจอร์ master data สี)
- `2026_08_31_000001_add_counter_query_indexes_to_orders_table.php` — **เพิ่ม index บน `orders.order_date`, `orders.due_date`, `(order_status, order_date)`**

> ⚠️ ตัวที่สองสำคัญมาก: ตาราง `orders` ไม่เคยมี index บนสองคอลัมน์นี้เลย ทั้งที่หน้าเคาน์เตอร์เรียงและกรองด้วยมันตลอด ถ้าไม่รัน งานแก้เรื่องความช้าจะได้ผลไม่เต็มที่

### 2. รันเทสทั้งหมด แล้วแก้ให้ผ่าน
```bash
php artisan test
npx vitest run
```

**ไฟล์เทสที่เขียน/แก้ไว้แต่ยังไม่เคยรัน:**

| ไฟล์ | สถานะ | ครอบคลุมอะไร |
|---|---|---|
| `tests/Feature/Http/Controllers/CounterPaginationTest.php` | **ใหม่** | pagination หน้าเคาน์เตอร์ + การ์ดสรุปต้องนับทุกออเดอร์ |
| `tests/Feature/Domain/OrderManagement/CreateOrderActionTest.php` | แก้ + เพิ่ม | artwork หลายภาพ, เวลาเปิดบิลไม่ถูกรีเซ็ตเป็นเที่ยงคืน |
| `tests/Feature/Domain/OrderManagement/UpdateOrderActionTest.php` | เพิ่มเคส | แก้ออเดอร์แล้วต้อง "เพิ่ม" ภาพ ไม่ใช่ "แทนที่" |
| `tests/Feature/Http/Controllers/OrderAndQcEndpointSecurityTest.php` | แก้ | validation artwork เปลี่ยนเป็น array (error key เป็น `shirt_artwork.0`) |
| `tests/Feature/Http/Controllers/CatalogItemQuickAddTest.php` | **ใหม่** | endpoint เพิ่มข้อมูล master data สี |
| `resources/js/pages/Orders/__tests__/Create.test.tsx` | แก้ | field เปลี่ยนเป็น `shirt_artwork_urls` (พหูพจน์) |
| `resources/js/components/domain/production/__tests__/ProductionBoardPage.test.tsx` | เพิ่มเคส | นับจำนวนรูปในแกลเลอรีให้ครบ |

### 3. เปิดหน้าจอจริงแล้วตรวจด้วยตา
```bash
npm run dev
```
ดู 2 หน้า: **หน้าเคาน์เตอร์** และ **หน้าเปิดบิลใหม่ (`/orders/create`)** ทั้งจอคอมและย่อเป็นมือถือ

---

## ⚠️ จุดเสี่ยงสูงสุด — ต้องตรวจเป็นพิเศษ

**ตัวเลขบนการ์ดสรุปห้องผลิต (หน้าเคาน์เตอร์)**

เดิมคำนวณที่ frontend ด้วย `deriveFloorStats()` ใน `resources/js/pages/counterStats.ts` จากออเดอร์ **ทั้งหมด**
ตอนนี้ย้ายไปคำนวณที่ backend แล้ว (`DashboardController::buildCounterFloorStats()`) เพื่อไม่ต้องส่งออเดอร์ทั้งหมดมาที่เบราว์เซอร์

ผมพอร์ตสูตรมาแบบบรรทัดต่อบรรทัด แต่ **ไม่เคยรันเทียบตัวเลขจริง** กรุณาตรวจว่าตัวเลขตรงกับก่อนแก้ วิธีตรวจที่ตรงที่สุด:
1. `git stash` เพื่อกลับไปเวอร์ชันเดิม → เปิดหน้าเคาน์เตอร์ → จดตัวเลขทุกการ์ด
2. `git stash pop` → เปิดใหม่ → เทียบว่าตรงกันทุกช่อง

เคสที่ต้องดูเป็นพิเศษ (ตรงนี้สูตรซับซ้อน):
- การแยกงานเข้าห้อง `heat_press` กับ `screen_flex` — ใช้การเช็คคำว่า `ซับ` หรือ `sublimation` ใน `job_type`
- ช่อง `pending_inspect` ของห้อง QC (ห้องอื่นใช้ `assigned`)
- ออเดอร์ที่ `delivery_method` เป็น null/ค่าแปลก ต้องตกลงช่อง "รับที่ร้าน" (`store_pickup`)
- ค่า `*_qty` ทุกตัว (ผลรวมจำนวนตัว ไม่ใช่จำนวนออเดอร์)

---

## 📋 สรุปงานที่ทำไปทั้งหมด (5 ก้อน)

### A. ฟีเจอร์ master data สี + quick add
- เพิ่ม combo box ที่พิมพ์สีใหม่แล้วบันทึกเข้า master data ได้เลย 9 ช่อง (เสื้อ 6 + กางเกง 3)
- ไฟล์: `MasterDataComboBox.tsx` (ใหม่), `ShirtCatalogController.php`, `routes/web.php`, `Create.tsx`
- **ความปลอดภัย**: endpoint ใช้ whitelist ของ storage key ห้ามส่ง key อะไรก็ได้เข้ามา

### B. เวลาเปิดบิล
- เดิมระบบ hardcode เวลาเป็น `00:00:00` ทุกออเดอร์ → แก้ให้บันทึกเวลาจริงตอนกดบันทึก
- **ผู้ใช้ระบุชัดเจนว่าไม่ต้องการช่องให้กรอกเวลา** — เก็บอัตโนมัติเงียบๆ ไม่ต้องแสดงในหน้าเปิดบิล
- แสดงเวลาในตารางหน้าเคาน์เตอร์ (ใต้วันที่ เป็น chip เล็กๆ พร้อมไอคอนนาฬิกา)

### C. artwork เสื้อ/กางเกง รองรับหลายภาพ + แก้บั๊ก print PDF
- ถอด `->singleFile()` ออกจาก media collection `shirt_artwork` / `pants_artwork`
- validation เปลี่ยนเป็น array + wildcard (`shirt_artwork.*`)
- **บั๊กที่เจอระหว่างทาง**: รูป artwork เสื้อ/กางเกง **ไม่เคยถูกพิมพ์ออก PDF เลยแม้แต่ภาพเดียว** ทั้งใน `Counter.tsx` และตัวนับ "รูปที่แนบทั้งหมด X รูป" ใน `ProductionBoardPage.tsx` → แก้แล้ว

### D. ปรับ UI ตารางหน้าเคาน์เตอร์ + รองรับมือถือ
- เดสก์ท็อป: หัวตารางแบบ uppercase, แถวสูง 56px, สลับสีแถว, hover ฟ้าอ่อน, จัดความกว้างคอลัมน์ให้รวมได้ 100% พอดี (ของเดิมรวมได้ 112%)
- มือถือ (< 768px): เปลี่ยนเป็นการ์ดรายออเดอร์ ปุ่มใหญ่กดง่าย
- คอลัมน์ "ประเภทงาน" แสดงชื่อเต็ม ไม่ตัดคำ (ตัดขึ้นบรรทัดใหม่แทน)

### E. แก้ปัญหาความช้าเมื่อข้อมูลเยอะ ← งานล่าสุด
1. **แบ่งหน้าที่ backend** หน้าละ 10 — เดิมโหลดออเดอร์ทั้งหมดตั้งแต่เปิดระบบ พร้อม eager load หนักทุกตัว
2. **แยก query เป็น 2 ชุด**: ชุดสรุป (4 คอลัมน์ + routing 6 ฟิลด์ + `SUM(quantity)`) สำหรับการ์ด, ชุดตาราง (relation หนัก) เฉพาะ 10 แถว
3. **ฟิลเตอร์วันที่ใช้ index ได้**: เปลี่ยนจาก `whereDate()` เป็นเทียบช่วง `>= 00:00:00` และ `< วันถัดไป`
4. **เพิ่ม index** (migration ข้อ 1 ด้านบน)

---

## 🚫 อย่าไป "แก้" สิ่งเหล่านี้ — เป็นการตัดสินใจโดยตั้งใจ

1. **`resources/js/pages/counterStats.ts` ไม่ใช่โค้ดตาย** — หน้าเคาน์เตอร์ไม่เรียกใช้แล้ว แต่เก็บไว้เป็น executable spec ที่ฝั่ง PHP พอร์ตตาม และยังมีเทส vitest คุมอยู่ ถ้าแก้สูตรที่ไหน ต้องแก้ทั้งสองที่
2. **แก้ออเดอร์แล้วภาพเก่าไม่ถูกลบ** — ตั้งใจให้เหมือน `reference_designs` ที่มีพฤติกรรมนี้อยู่เดิม (การเซฟ = เพิ่มภาพใหม่เท่านั้น) ไม่ได้ทำระบบลบภาพที่บันทึกแล้ว เพราะเจ้าของไม่ได้ขอ
3. **หน้า Orders/Index ยังใช้ `shirt_artwork_url` (เอกพจน์)** — ตั้งใจ เพราะเป็น thumbnail แถวละ 1 รูป
4. **`resolveGroupArtworkUrl()` ใน `ProductionBoardPage.tsx` แสดงรูปเดียว** — ตั้งใจ เพราะเป็นกล่องรูปบนฟอร์มพิมพ์ที่เลียนแบบฟอร์มกระดาษจริง ไม่ใช่แกลเลอรี
5. **`tsc --noEmit` มี error 54 ตัวเป็น baseline เดิมของโปรเจกต์** (preserveScroll, type cast ในเทส ฯลฯ) — ไม่ได้เกิดจากงานชุดนี้ ถ้าจะแก้ให้แยกเป็นงานต่างหาก อย่าปนกับงานนี้

---

## ✅ สิ่งที่ตรวจไปแล้ว (เท่าที่ cloud sandbox ทำได้)

- `php -l` ผ่านทุกไฟล์ PHP ที่แก้
- `npx tsc --noEmit -p tsconfig.json` = 54 errors เท่า baseline เดิม **ไม่มี error ใหม่จากงานชุดนี้**
- ตรวจ API ของ `@tanstack/react-virtual` v3.14.5 กับซอร์สจริงใน node_modules
- ตรวจว่าความกว้างคอลัมน์หัวตารางกับตัวตารางตรงกันทุกคอลัมน์และรวมได้ 100%

**ไม่เคยรัน**: `php artisan migrate`, `php artisan test`, `npx vitest run`, `npm run build`, และไม่เคยเห็นหน้าจอจริง

> เหตุผล: cloud sandbox ไม่มี PHP binary และ `vite`/`vitest` พังจาก rolldown native binding ไม่ตรง platform (โปรเจกต์อยู่บน macOS แต่ sandbox เป็น Linux ARM64)

---

## 💡 ข้อเสนอหลังเทสผ่านหมด

- ตอนนี้ทุกอย่างยัง uncommitted บน `main` — น่าจะแยก branch แล้วทยอย commit เป็นก้อนๆ ตาม A–E ด้านบน
- หน้าเคาน์เตอร์ยังไม่มี default filter (ดึงออเดอร์ทั้งหมดตั้งแต่เปิดระบบ) — ถ้าอยากให้เร็วขึ้นอีก พิจารณาใส่ค่าเริ่มต้นเป็นช่วง 30–90 วันล่าสุด หรือซ่อนออเดอร์ที่ completed/cancelled ออกจากค่าเริ่มต้น **แต่ต้องถามเจ้าของก่อน** เพราะเปลี่ยนพฤติกรรมการใช้งาน
- แถบค้นหา/ฟิลเตอร์เหนือตารางยังไม่ได้ปรับให้เหมาะกับมือถือ (ช่องค้นหากับปุ่มเบียดกันในจอแคบ) — เจ้าของรับทราบแล้ว รอสั่ง
