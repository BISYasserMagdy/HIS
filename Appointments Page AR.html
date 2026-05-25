<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مواعيد الأطباء</title>
    <style>
        :root {
            --bg: #f0f6ff;
            --surface: #ffffff;
            --primary: #1a73e8;
            --primary-dark: #0b3c7a;
            --text: #1f2937;
            --muted: #5b6b7b;
            --border: #dbe4f0;
        }

        body {
            margin: 0;
            font-family: 'Arial', 'Arabic Typesetting', Helvetica, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            direction: rtl;
        }

        header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            padding: 24px 32px;
        }

        header h1,
        header p {
            margin: 0;
        }

        nav {
            margin-top: 16px;
        }

        nav a {
            color: #fff;
            text-decoration: none;
            padding: 10px 16px;
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            transition: background 0.2s ease;
        }

        nav a:hover {
            background: rgba(255,255,255,0.2);
        }

        .lang-buttons {
            display: inline-flex;
            gap: 0.5rem;
            margin-right: 1rem;
        }

        .lang-btn {
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .lang-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .lang-btn.active {
            background: #fff;
            color: var(--primary);
        }

        main {
            max-width: 980px;
            margin: 0 auto;
            padding: 28px 20px 40px;
        }

        .intro {
            display: grid;
            gap: 18px;
            margin-bottom: 28px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 14px 30px rgba(23, 43, 77, 0.06);
            padding: 26px;
        }

        .card h2 {
            margin-top: 0;
            color: var(--primary-dark);
        }

        .appointment-form {
            display: grid;
            gap: 16px;
        }

        .appointment-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .appointment-form input,
        .appointment-form select,
        .appointment-form textarea {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #f8fbff;
            color: var(--text);
            font-size: 1rem;
            box-sizing: border-box;
            text-align: right;
        }

        .appointment-form input[type="date"] {
            cursor: pointer;
        }

        .appointment-form input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
        }

        .appointment-form input[type="time"] {
            cursor: pointer;
        }

        .appointment-form input[type="time"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
        }

        .appointment-form button {
            width: fit-content;
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 14px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
        }

        .appointment-form button:hover {
            background: var(--primary-dark);
        }

        .doctors-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }

        .doctor-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 20px;
        }

        .doctor-card h3 {
            margin-top: 0;
        }

        .doctor-card p {
            margin: 10px 0;
        }

        .doctor-card span {
            display: inline-block;
            margin-top: 10px;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .categories-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            margin-bottom: 28px;
        }

        .category-btn {
            padding: 14px 18px;
            border: 2px solid var(--border);
            background: var(--surface);
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            color: var(--text);
            transition: all 0.3s ease;
        }

        .category-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .category-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .doctors-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }

        .doctor-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 20px;
            display: none;
        }

        .doctor-card.active-category {
            display: block;
        }

        .schedule-container {
            overflow-x: auto;
        }

        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .schedule-table thead {
            background: var(--primary);
            color: #fff;
        }

        .schedule-table th {
            padding: 14px 12px;
            text-align: center;
            font-weight: 600;
            border: 1px solid var(--border);
        }

        .schedule-table th:last-child {
            text-align: right;
        }

        .schedule-table td {
            padding: 14px 12px;
            text-align: center;
            border: 1px solid var(--border);
        }

        .schedule-table td:last-child {
            text-align: right;
            font-weight: 600;
            background: #f8fbff;
        }

        .schedule-table tbody tr.highlighted td:first-child {
            color: #d4a017;
            background: #fffef0;
            box-shadow: inset 0 0 8px rgba(255, 215, 0, 0.2);
            font-weight: 700;
        }

        .availability-cell {
            border-radius: 8px;
            font-weight: 600;
            padding: 10px 8px;
            display: inline-block;
            min-width: 45px;
        }

        .availability-high {
            background: #c8e6c9;
            color: #1b5e20;
        }

        .availability-medium {
            background: #fff9c4;
            color: #f57f17;
        }

        .availability-low {
            background: #ffcdd2;
            color: #c62828;
        }

        .availability-none {
            background: #eeeeee;
            color: #424242;
        }

        .schedule-legend {
            display: flex;
            gap: 24px;
            margin-top: 20px;
            padding: 16px;
            background: #f8fbff;
            border-radius: 12px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .legend-box {
            width: 24px;
            height: 24px;
            border-radius: 4px;
        }

        @media (max-width: 640px) {
            header {
                padding: 20px 18px;
            }

            main {
                padding: 20px 18px 32px;
            }

            .schedule-table {
                font-size: 0.85rem;
            }

            .schedule-table th,
            .schedule-table td {
                padding: 10px 8px;
            }

            .availability-cell {
                min-width: 38px;
                padding: 8px 6px;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>مواعيد الأطباء</h1>
        <p>احجز موعدك واختر الطبيب المناسب لك.</p>
        <nav>
            <div class="lang-buttons">
                <button class="lang-btn" onclick="window.location.href='Appointments Page.html'">English</button>
                <button class="lang-btn active" onclick="changeLanguage('ar')">العربية</button>
            </div>
            <a href="Home Page AR.html">العودة للصفحة الرئيسية</a>
        </nav>
    </header>
    <main>
        <section class="intro card">
            <h2>احجز موعدك</h2>
            <p>اختر طبيبك وحدد التاريخ والوقت المناسب لك وقدم بيانات التواصل الخاصة بك. نستخدم نفس التصميم الهادئ والمحترف.</p>
        </section>

        <section class="card">
            <h2>بيانات الموعد</h2>
            <form class="appointment-form">
                <div>
                    <label for="patient-name">اسم المريض</label>
                    <input id="patient-name" type="text" placeholder="أدخل اسمك الكامل" required>
                </div>
                <div>
                    <label for="phone">رقم الهاتف</label>
                    <input id="phone" type="tel" placeholder="أدخل رقم هاتفك" required>
                </div>
                <div>
                    <label for="specialty">المجال الطبي / التخصص</label>
                    <select id="specialty" required>
                        <option value="">اختر مجالاً طبياً</option>
                        <option value="family-medicine">الطب العائلي</option>
                        <option value="cardiology">أمراض القلب</option>
                        <option value="pediatrics">طب الأطفال</option>
                        <option value="dermatology">الأمراض الجلدية</option>
                        <option value="orthopedics">جراحة العظام</option>
                        <option value="neurology">طب الأعصاب</option>
                        <option value="ophthalmology">أمراض العيون</option>
                        <option value="internal-medicine">الباطنة</option>
                        <option value="dentistry">طب الأسنان</option>
                    </select>
                </div>
                <div>
                    <label for="doctor">اختر الطبيب</label>
                    <select id="doctor" required>
                        <option value="">اختر طبيباً</option>
                        <option value="dr-smith" data-specialty="family-medicine">د. إيميلي سميث - الطب العائلي</option>
                        <option value="dr-khan" data-specialty="cardiology">د. أمير خان - أمراض القلب</option>
                        <option value="dr-lee" data-specialty="pediatrics">د. ميا لي - طب الأطفال</option>
                        <option value="dr-wong" data-specialty="dermatology">د. ديفيد وونج - الأمراض الجلدية</option>
                        <option value="dr-johnson" data-specialty="family-medicine">د. سارة جونسون - الطب العائلي</option>
                        <option value="dr-chen" data-specialty="cardiology">د. مايكل تشين - أمراض القلب</option>
                        <option value="dr-anderson" data-specialty="pediatrics">د. ليزا أندرسون - طب الأطفال</option>
                        <option value="dr-brown" data-specialty="dermatology">د. جيسيكا براون - الأمراض الجلدية</option>
                        <option value="dr-taylor" data-specialty="orthopedics">د. روبرت تايلور - جراحة العظام</option>
                        <option value="dr-martinez" data-specialty="orthopedics">د. جيمس مارتينيز - جراحة العظام</option>
                        <option value="dr-garcia" data-specialty="neurology">د. باتريشيا جارسيا - طب الأعصاب</option>
                        <option value="dr-lee-neuro" data-specialty="neurology">د. ستيفن لي - طب الأعصاب</option>
                        <option value="dr-white" data-specialty="ophthalmology">د. إليزابيث وايت - أمراض العيون</option>
                        <option value="dr-harris" data-specialty="ophthalmology">د. ويليام هاريس - أمراض العيون</option>
                        <option value="dr-ali" data-specialty="internal-medicine">د. علي حسن - الباطنة</option>
                        <option value="dr-fatima" data-specialty="internal-medicine">د. فاطمة الراشد - الباطنة</option>
                        <option value="dr-green" data-specialty="dentistry">د. راشيل جرين - طب الأسنان</option>
                        <option value="dr-davis" data-specialty="dentistry">د. كيفن ديفيس - طب الأسنان</option>
                    </select>
                </div>
                <div>
                    <label for="date">التاريخ المفضل</label>
                    <input id="date" type="date" required>
                    <small id="date-error" style="color: #d32f2f; display: none; margin-top: 4px;"></small>
                </div>
                <div>
                    <label for="time">الوقت المفضل</label>
                    <input id="time" type="time" required>
                </div>
                <div id="availability-notice" style="display: none; background: #fff3e0; border-left: 4px solid #ff9800; padding: 12px; border-radius: 8px; margin: 10px 0;">
                    <strong style="color: #ff9800;">⚠ إشعار التوفر:</strong>
                    <p id="notice-text" style="margin: 6px 0; font-size: 0.95rem;"></p>
                </div>
                <div>
                    <label for="notes">ملاحظات إضافية</label>
                    <textarea id="notes" rows="4" placeholder="أخبرنا بأي معلومات أخرى نحتاج معرفتها"></textarea>
                </div>
                <button type="submit">طلب موعد</button>
            </form>
        </section>

        <section class="card">
            <h2>جدول توفر الأطباء</h2>
            <p style="color: var(--muted); margin-bottom: 20px;">يعرض عدد الأطباء المتاحين حسب التخصص لكل يوم من أيام الأسبوع</p>
            <div class="schedule-container" id="schedule-container"></div>
        </section>
    </main>

    <script>
        // Doctor availability data (by day of week: 0=Sunday, 1=Monday, etc.)
        const doctorAvailability = {
            'dr-smith': { name: 'د. إيميلي سميث', specialty: 'family-medicine', days: [0, 1, 3] },
            'dr-khan': { name: 'د. أمير خان', specialty: 'cardiology', days: [2, 4] },
            'dr-lee': { name: 'د. ميا لي', specialty: 'pediatrics', days: [1, 2, 3, 4] },
            'dr-wong': { name: 'د. ديفيد وونج', specialty: 'dermatology', days: [0, 3, 4, 6] },
            'dr-johnson': { name: 'د. سارة جونسون', specialty: 'family-medicine', days: [2, 4, 6] },
            'dr-chen': { name: 'د. مايكل تشين', specialty: 'cardiology', days: [0, 1, 3] },
            'dr-anderson': { name: 'د. ليزا أندرسون', specialty: 'pediatrics', days: [0, 3, 4, 6] },
            'dr-brown': { name: 'د. جيسيكا براون', specialty: 'dermatology', days: [0, 2, 4] },
            'dr-taylor': { name: 'د. روبرت تايلور', specialty: 'orthopedics', days: [1, 2, 4] },
            'dr-martinez': { name: 'د. جيمس مارتينيز', specialty: 'orthopedics', days: [0, 3, 6] },
            'dr-garcia': { name: 'د. باتريشيا جارسيا', specialty: 'neurology', days: [0, 1, 3] },
            'dr-lee-neuro': { name: 'د. ستيفن لي', specialty: 'neurology', days: [2, 4, 6] },
            'dr-white': { name: 'د. إليزابيث وايت', specialty: 'ophthalmology', days: [2, 4, 6] },
            'dr-harris': { name: 'د. ويليام هاريس', specialty: 'ophthalmology', days: [0, 1, 3] },
            'dr-ali': { name: 'د. علي حسن', specialty: 'internal-medicine', days: [0, 1, 2, 3, 4] },
            'dr-fatima': { name: 'د. فاطمة الراشد', specialty: 'internal-medicine', days: [0, 2, 3, 4, 6] },
            'dr-green': { name: 'د. راشيل جرين', specialty: 'dentistry', days: [0, 1, 2, 3, 4] },
            'dr-davis': { name: 'د. كيفن ديفيس', specialty: 'dentistry', days: [0, 3, 4, 6] },
        };

        const dayNames = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        const dayShort = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        
        const specialtyLabels = {
            'family-medicine': 'الطب العائلي',
            'cardiology': 'أمراض القلب',
            'pediatrics': 'طب الأطفال',
            'dermatology': 'الأمراض الجلدية',
            'orthopedics': 'جراحة العظام',
            'neurology': 'طب الأعصاب',
            'ophthalmology': 'أمراض العيون',
            'internal-medicine': 'الباطنة',
            'dentistry': 'طب الأسنان'
        };

        // Generate availability schedule
        function generateSchedule() {
            const specialties = Object.keys(specialtyLabels);
            const scheduleData = {};

            // Initialize schedule
            specialties.forEach(specialty => {
                scheduleData[specialty] = {};
                for (let day = 0; day < 7; day++) {
                    scheduleData[specialty][day] = 0;
                }
            });

            // Count available doctors per specialty per day
            Object.values(doctorAvailability).forEach(doctor => {
                doctor.days.forEach(day => {
                    scheduleData[doctor.specialty][day]++;
                });
            });

            return scheduleData;
        }

        // Get availability status for coloring
        function getAvailabilityStatus(count) {
            if (count === 0) return 'availability-none';
            if (count === 1) return 'availability-low';
            if (count === 2) return 'availability-medium';
            return 'availability-high';
        }

        // Render schedule table
        function renderSchedule() {
            const scheduleData = generateSchedule();
            const specialties = Object.keys(specialtyLabels);
            
            let html = '<table class="schedule-table"><thead><tr><th>المجال الطبي</th>';
            
            // Add day headers (reversed for RTL)
            dayShort.forEach(day => {
                html += `<th>${day}</th>`;
            });
            html += '</tr></thead><tbody>';

            // Add rows for each specialty
            specialties.forEach(specialty => {
                html += `<tr data-specialty="${specialty}"><td>${specialtyLabels[specialty]}</td>`;
                
                for (let day = 0; day < 7; day++) {
                    const count = scheduleData[specialty][day];
                    const status = getAvailabilityStatus(count);
                    html += `<td><span class="availability-cell ${status}">${count}</span></td>`;
                }
                
                html += '</tr>';
            });

            html += '</tbody></table>';

            // Add legend
            html += `
                <div class="schedule-legend">
                    <div class="legend-item">
                        <div class="legend-box availability-high"></div>
                        <span>3+ أطباء متاحون</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-box availability-medium"></div>
                        <span>طبيبان متاحان</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-box availability-low"></div>
                        <span>طبيب واحد متاح</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-box availability-none"></div>
                        <span>لا توجد أطباء متاحون</span>
                    </div>
                </div>
            `;

            document.getElementById('schedule-container').innerHTML = html;
        }

        // Get available doctors for a specific date
        function getAvailableDoctorsForDate(dateString, specialty = '') {
            if (!dateString) return [];
            
            const date = new Date(dateString);
            const dayOfWeek = date.getDay();
            
            return Object.entries(doctorAvailability)
                .filter(([id, doctor]) => {
                    const dayMatch = doctor.days.includes(dayOfWeek);
                    const specialtyMatch = specialty === '' || doctor.specialty === specialty;
                    return dayMatch && specialtyMatch;
                })
                .map(([id, doctor]) => ({ id, ...doctor }));
        }

        function changeLanguage(lang) {
            document.querySelectorAll('.lang-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
        }

        // Update doctor list when date or specialty changes
        function updateAvailableDoctors() {
            const dateInput = document.getElementById('date').value;
            const specialty = document.getElementById('specialty').value;
            const availabilityNotice = document.getElementById('availability-notice');
            const noticeText = document.getElementById('notice-text');
            const dateError = document.getElementById('date-error');
            
            // Get available doctors for selected date
            const availableDoctors = getAvailableDoctorsForDate(dateInput, specialty);
            
            if (dateInput) {
                const date = new Date(dateInput);
                const dayName = dayNames[date.getDay()];
                
                // Check if it's a weekend (Sunday = 0, Saturday = 6)
                if (date.getDay() === 0 || date.getDay() === 6) {
                    dateError.textContent = `⚠ عدد محدود من الأطباء المتاحين يوم ${dayName}`;
                    dateError.style.display = 'block';
                } else {
                    dateError.style.display = 'none';
                }
                
                // Show notice if few doctors available
                if (availableDoctors.length === 0) {
                    availabilityNotice.style.display = 'block';
                    noticeText.textContent = `لا يوجد أطباء متاحون في التخصص المحدد يوم ${dayName}. الرجاء اختيار تاريخ أو تخصص آخر.`;
                } else if (availableDoctors.length < 3) {
                    availabilityNotice.style.display = 'block';
                    noticeText.textContent = `يوجد فقط ${availableDoctors.length} طبيب(أطباء) متاح(ون) في هذا التخصص يوم ${dayName}.`;
                } else {
                    availabilityNotice.style.display = 'none';
                }
                
                // Filter doctor dropdown
                filterDoctorsByDate(dateInput, specialty);
            } else {
                availabilityNotice.style.display = 'none';
                dateError.style.display = 'none';
            }
        }

        // Filter doctor dropdown based on date and specialty
        function filterDoctorsByDate(dateString, specialty) {
            const availableDoctors = getAvailableDoctorsForDate(dateString, specialty);
            const doctorSelect = document.getElementById('doctor');
            const availableDoctorIds = new Set(availableDoctors.map(d => d.id));
            
            // Get current doctor selection
            const currentSelection = doctorSelect.value;
            
            doctorSelect.querySelectorAll('option').forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else if (availableDoctorIds.has(option.value)) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });
            
            // Reset selection if current doctor is not available
            if (currentSelection && !availableDoctorIds.has(currentSelection)) {
                doctorSelect.value = '';
            }
        }

        // Highlight schedule row when specialty is selected
        function highlightSpecialtyInSchedule(specialty) {
            // Remove all existing highlights
            document.querySelectorAll('.schedule-table tbody tr.highlighted').forEach(row => {
                row.classList.remove('highlighted');
            });
            
            // Add highlight to selected specialty row
            if (specialty) {
                const targetRow = document.querySelector(`.schedule-table tbody tr[data-specialty="${specialty}"]`);
                if (targetRow) {
                    targetRow.classList.add('highlighted');
                }
            }
        }

        // Filter doctors based on selected specialty
        document.getElementById('specialty').addEventListener('change', function() {
            const selectedSpecialty = this.value;
            const doctorSelect = document.getElementById('doctor');
            const allOptions = doctorSelect.querySelectorAll('option');
            
            // Highlight the selected specialty in schedule
            highlightSpecialtyInSchedule(selectedSpecialty);
            
            // Reset doctor selection
            doctorSelect.value = '';
            
            allOptions.forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    const optionSpecialty = option.getAttribute('data-specialty');
                    if (selectedSpecialty === '') {
                        option.style.display = 'block';
                    } else if (optionSpecialty === selectedSpecialty) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                }
            });
            
            // Re-apply date filter if date is selected
            if (document.getElementById('date').value) {
                updateAvailableDoctors();
            }
        });

        // Handle date change - update available doctors
        document.getElementById('date').addEventListener('change', updateAvailableDoctors);

        // Open date picker immediately on focus/click
        document.getElementById('date').addEventListener('click', function() {
            this.showPicker();
        });
        
        document.getElementById('date').addEventListener('focus', function() {
            this.showPicker();
        });

        // Open time picker immediately on focus/click
        document.getElementById('time').addEventListener('click', function() {
            this.showPicker();
        });
        
        document.getElementById('time').addEventListener('focus', function() {
            this.showPicker();
        });

        // Form submission with validation
        document.querySelector('.appointment-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const selectedDate = document.getElementById('date').value;
            const selectedDoctor = document.getElementById('doctor').value;
            
            if (!selectedDate || !selectedDoctor) {
                alert('الرجاء اختيار كل من التاريخ والطبيب.');
                return;
            }
            
            // Check if selected doctor is available on selected date
            const availableDoctors = getAvailableDoctorsForDate(selectedDate);
            const isDoctorAvailable = availableDoctors.some(d => d.id === selectedDoctor);
            
            if (!isDoctorAvailable) {
                const date = new Date(selectedDate);
                const dayName = dayNames[date.getDay()];
                const doctorName = doctorAvailability[selectedDoctor]?.name || 'الطبيب المحدد';
                alert(`${doctorName} غير متاح يوم ${dayName}. الرجاء اختيار تاريخ أو طبيب آخر.`);
                return;
            }
            
            // If validation passes, proceed with form submission
            alert('تم تقديم طلب الموعد بنجاح!');
            // TODO: Send form data to backend
            // this.submit();
        });

        // Initialize schedule on page load
        document.addEventListener('DOMContentLoaded', function() {
            renderSchedule();
        });
    </script>
</body>
</html>
