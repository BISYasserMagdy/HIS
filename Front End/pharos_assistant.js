/*!
 * Pharos HIS — floating health chatbot (Egyptian Arabic)
 * Drop-in: <script src="chatbot_widget.js" defer></script> before </body> on any page.
 * Optional override before it loads:
 *   window.PHAROS_CHAT_CONFIG = { api: '...', appointmentsUrl: '...' };
 */
(function () {
  'use strict';
  if (window.__pharosChatLoaded) return;
  window.__pharosChatLoaded = true;

  var CFG = Object.assign({
    api: '../Back End/chatbot_api.php',
    appointmentsUrl: 'Online_Consultation_Page.html',
    emergencyNumber: '123'
  }, window.PHAROS_CHAT_CONFIG || {});

  // ───────────────────────── knowledge base ─────────────────────────
  // medTopic = key in chatbot_api.php OTC_TOPICS. No medTopic => no medicines are offered
  // (doctor / prescription needed), and the user is pointed to appointments instead.
  var KB = {"البرد": {"keywords": ["برد", "زكام", "رشح", "كحة", "حمى", "كحه", "كحة", "سعال", "عطس", "احتقان", "زور", "التهاب الحلق", "سخونيه خفيفه", "cold", "cough"], "response": "البرد الشديد ممكن نتعامل معاه بـ:\n✓ نوم كويس والراحة\n✓ اشرب سوائل دافية مثل الشاي والعسل\n✓ استعمل مرطبات الأنف\n✓ لو الأعراض استمرت أكتر من أسبوع روح للدكتور", "medTopic": "cold"}, "الانفلونزا": {"keywords": ["انفلونزا", "فلو", "حمى شديدة", "ألم في العضلات"], "response": "الإنفلونزا بتحتاج:\n✓ اعزل نفسك عشان ما تعدي الناس التانية\n✓ اشرب سوائل وتناول طعام صحي\n✓ خذ راحتك ونام\n✓ لو الحرارة عالية ممكن تاخد خافض حرارة زي الباراسيتامول حسب تعليمات الصيدلي\n⚠️ لو الحالة ساءت أو حصل ضيق في التنفس اتصل بالدكتور فوراً", "medTopic": "pain"}, "الصداع": {"keywords": ["صداع", "وجع الراس", "وجع راسي", "راسي", "headache"], "response": "للصداع العادي:\n✓ اشرب مية برد\n✓ استرخي في مكان هادي\n✓ لو الصداع مستمر تقدر تاخد مسكن آمن\n✓ تقليل الكافيين والمنبهات\n⚠️ لو الصداع شديد جداً أو مصحوب بأعراض تانية استشير دكتور", "medTopic": "pain"}, "الشقيقة": {"keywords": ["شقيقة", "ميجرين", "ألم نصف الراس", "دوخة مع صداع"], "response": "الشقيقة (الميجرين) بتحتاج:\n✓ اعزل نفسك في مكان هادي بلا أصوات\n✓ اطفي الأضواء القوية\n✓ اشرب مية\n✓ استرخي وحاول تنام\n✓ استعمل مسكنات متخصصة للشقيقة\n🏥 لو متكررة استشير دكتور متخصص", "needsDoctor": true}, "آلام المعدة": {"keywords": ["معدة", "بطن", "ألم في البطن", "انتفاخ", "غازات", "حموضه", "حرقان", "حرقان في المعده", "ارتجاع", "stomach", "heartburn"], "response": "لآلام المعدة:\n✓ تجنب الأكل الدهني والحار\n✓ اشرب مية بدفء ببطء\n✓ جرب الزنجبيل أو البابونج\n✓ تناول طعام خفيف وسهل الهضم\n✓ قلل من القهوة والمنبهات\n⚠️ لو الألم مستمر أكتر من يومين روح للدكتور", "medTopic": "stomach"}, "الإمساك": {"keywords": ["امساك", "صعوبه في التبرز", "constipation"], "response": "للإمساك:\n✓ اشرب مية كتير - 8 أكواب في اليوم على الأقل\n✓ كل ألياف أكتر (خضار وفاكهة)\n✓ حرك نفسك وتمرن\n✓ لا تتأخر لما تحس بالرغبة\n✓ شاي الينسون ممكن يفيد\n🏥 لو استمر أكتر من أسبوع اتصل بالدكتور", "medTopic": "constipation"}, "الأرق": {"keywords": ["أرق", "ما أقدر أنام", "نوم سيء", "مشاكل النوم", "نوم", "انام", "sleep", "insomnia"], "response": "للأرق والنوم السيء:\n✓ حافظ على روتين نوم منتظم\n✓ نام بوقت واحد كل يوم\n✓ بلاش كافيين قبل النوم بـ 4-5 ساعات\n✓ غرفتك تكون هادية وباردة\n✓ تجنب الموبايل قبل النوم بنص ساعة\n✓ مارس رياضة أثناء النهار\n💆 تجربة الاسترخاء والتأمل مفيدة", "needsDoctor": true}, "السكري": {"keywords": ["سكري", "السكر", "مرض السكر", "السكر في الدم"], "response": "السكري بيحتاج متابعة:\n✓ التزم بالحمية - قلل السكريات البيضاء\n✓ كل أطعمة صحية متوازنة\n✓ تمرن يومياً لو 30 دقيقة\n✓ قيس السكر بانتظام\n✓ خذ الأدوية بانتظام زي ما قال الدكتور\n✓ اهتم بصحتك النفسية\n🏥 لازم تكون تحت إشراف دكتور متخصص\n\n💡 اكتب 'السكري والتغذية' عشان تعرف الحمية المفصلة للسكري", "needsDoctor": true}, "السكري والتغذية": {"keywords": ["سكري غذاء", "سكري تغذية", "سكري حمية", "سكري أكل", "نظام غذائي سكري", "تغذية السكري", "السكري والتغذيه", "السكري و التغذيه", "غذاء السكري", "اكل السكري", "حميه السكري", "تغذيه السكري", "diabetes diet"], "response": "📋 نصائح هامة للتغذية الصحية لمرضى السكر\n\n✅ التوصيات الموصى بها:\n\n🔹 الاهتمام بمواعيد الوجبات وضبطها مع العلاج حسب توصية الطبيب\n\n🔹 لازم تكون تكون الوجبة مكتملة العناصر الغذائية: نشويات – بروتين – خضراوات – دهون صحية – منتجات ألبان\n\n🔹 الحرص على وجود خضراوات السلطة سواء مقطعة أو سليمة في كل الوجبات:\nطماطم – خيار – بصل – فلفل – خس – جزر – فجل – بنجر – خضرة (جرجير – بقدونس – كسبرة)\n\n🔹 الحرص على وجود البروتين في كل الوجبات حسب الذوق والإمكانيات:\nالبيض – البقوليات (الفول – العدس – الحمص – الفاصولياء – اللوبيا) – الجبن والحليب (القريش – جبن قليلة الدهون – الرايب – الزبادى) – التونة – الأسماك – الطيور بدون الجلد – اللحوم الحمراء قليلة الدهن – الفطر أو الماشروم\n\n🔹 يفضل استخدام النشويات الآتية - الحبوب الكاملة:\nدقيق القمح الكامل (به ردة) – الشوفان – البليلة – البرغل – الفريك\n\n🔹 الأرز البني – المكرونة البنية\n\n🔹 ثمرة بطاطس أو بطاطا في حجم قبضة اليد\n\n🔹 نكتفي برغيف بلدى صغير أو 2 توست بني أو 5-4 معلق أرز أو مكرونة بني\n\n🔹 الإكثار من الخضار المطبوخ الغير نشوي:\nكوسة – باذنجان – فاصوليا خضراء – سبانخ – بامية – ملوخية – البروكلى – القرنبيط\n\n🔹 عند طبخ القلقاس والبطاطس والبسلة نراعي تقليل النشويات معهم\n\n🍎 الفاكهة:\nثمرتان فاكهة في اليوم في حجم قبضة اليد:\nتفاح – موز – برتقال – جوافة – يوسفي – مانجو – كيوي\n\nأو مج كبير من: مكعبات البطيخ – الكنتالوب – الاناناس – الفراولة\n\nأو 10 عنبات أو 3 تمر – بلح أو 2 تين أو 3 مشمش\n\n🫒 استخدام الدهون الصحية في الأكل:\nزيت الزيتون – السمن البلدى (ملعقة صغيرة في الطبخ) – زبدة الفول السوداني (بدون سكر أو زيوت مهدرجة) – المكسرات النية الغير المحمصة الغير مملحة – القليل من زيت عباد الشمس وزيت الذرة\n\n🚫 للتحلية: لا يزيد استخدام السكر الأبيض في اليوم عن ملعقة ونصف\n\n💧 الحرص على شرب 8 كوب ماء مقسمين على مدار اليوم\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n🚫 الممنوعات (الأطعمة التي يجب تجنبها):\n\n❌ التدخين\n\n❌ المعلبات المحلاة بالفركتوز الصناعي المكتوب عليها (لايت أو دايت)\n\n❌ المربى والحلاوة الطحينية\n\n❌ علب العصائر حتى التي مكتوب عليها بدون سكر\n\n❌ الدهون المهدرجة مثل السمن والزبد الصناعي ومخبوزات وكيك الأفران\n\n❌ اللحوم المصنعة مثل اللانشون والسوسيس والبلوبيف\n\n❌ مرقة الدجاج المصنعة والصلصة المحفوظة\n\n❌ أكياس التسالي مثل الشيبسي والبسكوت\n\n❌ الحلويات الشرقية والغربية\n\n❌ الوجبات السريعة\n\n❌ المياه الغازية\n\n❌ الخمور\n\n🏥 استشير الدكتور دائماً قبل عمل أي تغييرات على حميتك"}, "ضغط الدم": {"keywords": ["ضغط دم", "ضغط مرتفع", "ضغط منخفض", "ضغطي", "blood pressure"], "response": "لضغط الدم المرتفع:\n✓ قلل الملح في الطعام\n✓ تمرن بانتظام (مشي 30 دقيقة)\n✓ قلل الدهون والأطعمة الدسمة\n✓ قلل الكافيين والتدخين\n✓ انتظم على الأدوية\n✓ استرخي وقلل التوتر\n⚠️ قيس ضغطك بانتظام والتزم بالدكتور", "needsDoctor": true}, "القلق": {"keywords": ["قلق", "توتر", "ضغط عصبي", "أرق من الخوف"], "response": "للقلق والتوتر:\n✓ تنفس بهدوء - تنفس عميق من الأنف لمدة 4 ثواني\n✓ مارس اليوجا أو التأمل\n✓ ابتعد عن مصادر التوتر لو أمكن\n✓ تحدث مع حد ثقة فيهم\n✓ مارس الرياضة\n✓ اشرب شاي ألعشاب مهدئة (ليمون بالعسل)\n🏥 لو القلق شديد شوف دكتور نفسي", "needsDoctor": true}, "الجلد": {"keywords": ["جلد", "حكة", "طفح جلدي", "حساسية جلد", "حب الشباب"], "response": "مشاكل الجلد:\n✓ اغسل الوش بمية دافية وصابون لطيف\n✓ رطب جلدك بعد الغسيل\n✓ تجنب المنتجات القاسية\n✓ لا تحك الجلد إذا كانت حكة\n✓ ابعد عن المواد المسببة للحساسية\n⚠️ لو الحالة سيئة استشير دكتور جلدية", "needsDoctor": true}, "النصائح الصحية": {"keywords": ["نصيحة صحية", "نصائح", "عيش صحي", "حياة صحية"], "response": "نصائح صحية عامة:\n✓ اشرب مية كتير يومياً (8 أكواب)\n✓ نام من 7-8 ساعات يومياً\n✓ مارس رياضة 30 دقيقة يومياً\n✓ كل خضار وفاكهة يومياً\n✓ قلل الملح والسكريات\n✓ ما تدخن ولا تشرب كحول\n✓ اعمل فحوصات دورية\n✓ ركز على الصحة النفسية"}, "التطعيمات": {"keywords": ["تطعيم", "لقاح", "كورونا", "شلل", "حصبة"], "response": "التطعيمات مهمة جداً:\n✓ اللقاحات تحميك من أمراض خطيرة\n✓ اتبع جدول التطعيمات الموصى به\n✓ خذ التطعيمات في الوقت المناسب\n✓ اسأل الدكتور عن أي آثار جانبية\n✓ احتفظ برقم التطعيمات\n✓ لا تقلق من الآثار الجانبية البسيطة\n🏥 استشير دكتور قبل أي تطعيم لو عندك مشاكل صحية"}, "التغذية": {"keywords": ["أكل", "غذاء", "تغذية", "بروتين", "كالسيوم", "فيتامينات"], "response": "التغذية السليمة:\n✓ كل بروتين (دجاج، سمك، بيض، بقول)\n✓ اشمل الخضار والفاكهة في كل وجبة\n✓ اشرب لبن ومشتقاته (كالسيوم)\n✓ كل حبوب كاملة بدل البيضاء\n✓ قلل السكريات والدهون الضارة\n✓ ما تتخطاش الفطور\n✓ اشرب مية كتير"}, "الرياضة": {"keywords": ["رياضة", "تمرين", "جيم", "مشي", "اللياقة"], "response": "الرياضة والحركة:\n✓ اعمل رياضة متوازنة (قلب وقوة ومرونة)\n✓ ابدأ تدريجياً لا تعمل نفسك\n✓ مشي 30 دقيقة يومياً\n✓ سباحة رياضة ممتازة\n✓ اليوجا والاسترخاء مفيدة\n✓ مارس رياضة تحبها\n✓ احمي نفسك من الاصابات\n💪 الاستمرارية أهم من الشدة"}, "الحرارة": {"keywords": ["حراره", "سخونيه", "سخن", "temperature", "fever", "حمى"], "response": "لو عندك حرارة:\n✓ اشرب سوائل كتير وارتاح\n✓ البس خفيف وخلي الغرفة مهوّاة\n✓ ممكن تاخد خافض حرارة زي الباراسيتامول حسب تعليمات الصيدلي\n⚠️ لو الحرارة فوق 39 أو استمرت أكتر من 3 أيام، أو معاها تشنجات أو طفح أو صعوبة في التنفس، روح للدكتور فوراً", "medTopic": "pain"}, "الجروح": {"keywords": ["جرح", "جروح", "خدش", "عوره", "حرق بسيط", "wound", "cut"], "response": "للجروح البسيطة:\n✓ اغسل إيدك الأول\n✓ اغسل الجرح بمية جارية نضيفة وصابون لطيف\n✓ اضغط عليه بشاش نضيف لو بينزف\n✓ غطيه بضماد نضيف وغيره كل يوم\n⚠️ لو الجرح عميق أو النزيف مش بيقف أو فيه صديد أو احمرار بيزيد، روح الطوارئ", "medTopic": "firstaid"}, "الفيتامينات": {"keywords": ["فيتامين", "vitamin", "مكمل", "مكملات"], "response": "الفيتامينات بتيجي أساساً من أكل متنوع. المكملات ممكن تفيد في حالات معينة، ولازم متزودش عن الجرعة. لو عندك نقص أو أمراض مزمنة أو حامل، استشير دكتور أو صيدلي الأول.", "medTopic": "vitamins"}};

  // ───────────────────────── text helpers ─────────────────────────
  function norm(s) {
    return String(s).toLowerCase()
      .replace(/[ً-ْـ]/g, '')
      .replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
      .replace(/\s+/g, ' ').trim();
  }
  var KB_N = Object.keys(KB).map(function (k) {
    return { key: k, entry: KB[k], kws: KB[k].keywords.map(norm) };
  });

  var RED_FLAGS = ['الم في الصدر', 'وجع في الصدر', 'الم الصدر', 'ضيق نفس', 'ضيق في التنفس', 'صعوبه في التنفس', 'مش قادر اتنفس',
    'نهجان', 'فقدان الوعي', 'اغماء', 'غيبوبه', 'نزيف شديد', 'نزيف حاد', 'تشنج', 'جلطه', 'سكته', 'التواء في الوجه', 'كلامي تقيل',
    'تسمم', 'ابتلع', 'حساسيه شديده', 'تورم الوجه', 'chest pain', "can't breathe", 'cannot breathe', 'unconscious', 'stroke', 'seizure'];
  var SELF_HARM = ['انتحر', 'اقتل نفسي', 'اذي نفسي', 'ااذي نفسي', 'عايز اموت', 'مش عايز اعيش', 'suicide', 'kill myself', 'hurt myself'];
  var GREET = ['اهلا', 'مرحبا', 'سلام', 'السلام عليكم', 'صباح الخير', 'مساء الخير', 'هاي', 'hello', 'hi', 'hey'];
  var APPT = ['حجز', 'احجز', 'موعد', 'مواعيد', 'دكتور', 'طبيب', 'استشاره', 'appointment', 'doctor', 'book'];
  var PHARM = ['صيدليه', 'صيدليات', 'دوا', 'ادويه', 'علاج', 'اطلب', 'اوردر', 'order', 'pharmacy', 'medicine'];

  function hasAny(msg, list) {
    for (var i = 0; i < list.length; i++) if (msg.indexOf(list[i]) !== -1) return true;
    return false;
  }
  // Short keywords (<=3 chars, e.g. "برد", "فلو", "جلد") must match as whole words
  // (optional Arabic prefix/suffix) so "فلوس" or "مشاكل" never trigger a topic.
  var reCache = {};
  function kwMatch(msg, kw) {
    if (!kw) return false;
    if (kw.length > 3) return msg.indexOf(kw) !== -1;
    var re = reCache[kw];
    if (!re) {
      re = reCache[kw] = new RegExp('(^|\\s)(?:ال|وال|بال|و|ب|ل)?' + kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?:ي|ك|ه|ها|نا)?(?=\\s|$)');
    }
    return re.test(msg);
  }
  function hasWord(msg, list) {
    var words = msg.split(' ');
    return list.some(function (g) { return g.indexOf(' ') === -1 ? words.indexOf(g) !== -1 : msg.indexOf(g) !== -1; });
  }
  function findTopic(msg) {
    var best = null, bestLen = 0;
    KB_N.forEach(function (t) {
      t.kws.forEach(function (kw) {
        if (kw.length > bestLen && kwMatch(msg, kw)) { best = t; bestLen = kw.length; }
      });
    });
    return best;
  }

  // ───────────────────────── tiny DOM helper ─────────────────────────
  function h(tag, props) {
    var el = document.createElement(tag);
    props = props || {};
    Object.keys(props).forEach(function (k) {
      var v = props[k];
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.indexOf('on') === 0 && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (v !== null && v !== undefined && v !== false) el.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c === null || c === undefined || c === false) continue;
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return el;
  }

  // ───────────────────────── styles ─────────────────────────
  var CSS = '' +
  '.phc-root{position:fixed;right:20px;bottom:20px;z-index:2147483000;font-family:"Segoe UI",Tahoma,Arial,sans-serif;direction:rtl;font-size:15px;line-height:1.55}' +
  '.phc-root *{box-sizing:border-box}' +
  '.phc-fab{width:62px;height:62px;border-radius:50%;border:0;cursor:pointer;background:linear-gradient(135deg,#0f2244,#1b4a7a);color:#fff;font-size:28px;box-shadow:0 8px 24px rgba(15,34,68,.35);display:flex;align-items:center;justify-content:center;position:relative;transition:transform .2s}' +
  '.phc-fab:hover{transform:scale(1.07)}' +
  '.phc-fab:focus-visible,.phc-btn:focus-visible,.phc-chip:focus-visible,.phc-x:focus-visible{outline:3px solid #c9a84c;outline-offset:2px}' +
  '.phc-fab::after{content:"";position:absolute;inset:-5px;border-radius:50%;border:2px solid #c9a84c;opacity:.0;animation:phc-pulse 2.6s ease-out 1.2s 3}' +
  '@keyframes phc-pulse{0%{transform:scale(.9);opacity:.8}100%{transform:scale(1.35);opacity:0}}' +
  '.phc-panel{position:absolute;right:0;bottom:78px;width:372px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 110px);background:#fff;color:#16233a;border-radius:18px;box-shadow:0 18px 50px rgba(10,25,50,.35);display:none;flex-direction:column;overflow:hidden;border:1px solid #d9e1ec}' +
  '.phc-root.phc-open .phc-panel{display:flex}' +
  '.phc-head{background:linear-gradient(135deg,#0f2244,#1b4a7a);color:#fff;padding:12px 14px;display:flex;align-items:center;gap:10px}' +
  '.phc-head b{font-size:1rem}.phc-head small{display:block;opacity:.8;font-size:.72rem}' +
  '.phc-x{margin-inline-start:auto;background:rgba(255,255,255,.15);border:0;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:18px}' +
  '.phc-msgs{flex:1;overflow-y:auto;padding:14px;background:#f4f7fb;display:flex;flex-direction:column;gap:10px}' +
  '.phc-m{max-width:88%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}' +
  '.phc-m.bot{background:#fff;border:1px solid #dde5f0;align-self:flex-start;border-top-right-radius:4px}' +
  '.phc-m.user{background:#0f2244;color:#fff;align-self:flex-end;border-top-left-radius:4px}' +
  '.phc-m.warn{background:#fff3f0;border-color:#f3b4a8;color:#7a1d0e}' +
  '.phc-block{align-self:stretch;max-width:100%}' +
  '.phc-chips{display:flex;flex-wrap:wrap;gap:6px}' +
  '.phc-chip{background:#fff;border:1px solid #c9a84c;color:#0f2244;border-radius:999px;padding:6px 12px;cursor:pointer;font-size:.84rem;font-family:inherit}' +
  '.phc-chip:hover{background:#fbf5e3}' +
  '.phc-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;background:#c9a84c;color:#0f2244;border:0;border-radius:10px;padding:8px 14px;font-weight:700;cursor:pointer;text-decoration:none;font-size:.88rem;font-family:inherit}' +
  '.phc-btn.alt{background:#fff;border:1px solid #0f2244;color:#0f2244}' +
  '.phc-btn[disabled]{opacity:.55;cursor:not-allowed}' +
  '.phc-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}' +
  '.phc-card{background:#fff;border:1px solid #dde5f0;border-radius:12px;padding:10px 12px;margin-top:8px}' +
  '.phc-card h4{margin:0 0 2px;font-size:.95rem}.phc-card .sub{color:#5b6b82;font-size:.8rem}' +
  '.phc-br{border-top:1px dashed #dde5f0;margin-top:8px;padding-top:8px;display:flex;align-items:center;gap:8px;justify-content:space-between}' +
  '.phc-br .n{font-size:.85rem}.phc-br .d{color:#5b6b82;font-size:.75rem}' +
  '.phc-form{display:flex;flex-direction:column;gap:8px}' +
  '.phc-form label{font-size:.8rem;color:#42536b;display:flex;flex-direction:column;gap:3px}' +
  '.phc-form input,.phc-form select,.phc-form textarea{border:1px solid #c5d0e0;border-radius:8px;padding:8px;font:inherit;font-size:.9rem;background:#fff;color:#16233a;width:100%}' +
  '.phc-err{color:#b3261e;font-size:.8rem;min-height:1em}' +
  '.phc-foot{padding:8px 10px;background:#fff;border-top:1px solid #e3e9f2}' +
  '.phc-in{display:flex;gap:6px}' +
  '.phc-in input{flex:1;border:1px solid #c5d0e0;border-radius:999px;padding:9px 14px;font:inherit;font-size:.92rem;color:#16233a;background:#fff;min-width:0}' +
  '.phc-send{background:#0f2244;color:#fff;border:0;border-radius:50%;width:40px;height:40px;cursor:pointer;font-size:16px;flex:none}' +
  '.phc-note{font-size:.68rem;color:#6a7a90;text-align:center;margin-top:6px}' +
  '.phc-typing{display:flex;gap:4px;padding:4px 2px}.phc-typing i{width:7px;height:7px;border-radius:50%;background:#8aa0bd;animation:phc-b 1s infinite}' +
  '.phc-typing i:nth-child(2){animation-delay:.15s}.phc-typing i:nth-child(3){animation-delay:.3s}' +
  '@keyframes phc-b{0%,80%,100%{opacity:.3}40%{opacity:1}}' +
  '@media (max-width:480px){.phc-root{right:12px;bottom:12px}.phc-panel{position:fixed;right:8px;left:8px;bottom:84px;width:auto;max-width:none;height:calc(100vh - 100px)}}' +
  '@media (prefers-reduced-motion:reduce){.phc-fab,.phc-fab::after,.phc-typing i{animation:none;transition:none}}';

  // ───────────────────────── UI skeleton ─────────────────────────
  var state = { visits: 0, loc: null, locAsked: false, busy: false, open: false };

  var root, panel, msgs, input, fab;

  function build() {
    var style = h('style'); style.textContent = CSS; document.head.appendChild(style);

    msgs = h('div', { class: 'phc-msgs', role: 'log', 'aria-live': 'polite', 'aria-label': 'المحادثة' });
    input = h('input', { type: 'text', placeholder: 'اكتب سؤالك أو الأعراض اللي حاسس بيها…', maxlength: '300', 'aria-label': 'اكتب رسالتك', autocomplete: 'off' });
    var send = h('button', { class: 'phc-send', type: 'submit', 'aria-label': 'إرسال', text: '➤' });
    var form = h('form', { class: 'phc-in', onsubmit: function (e) { e.preventDefault(); onUserSend(); } }, input, send);

    panel = h('div', { class: 'phc-panel', role: 'dialog', 'aria-label': 'مساعد Pharos الصحي', 'aria-modal': 'false' },
      h('div', { class: 'phc-head' },
        h('span', { text: '🩺', 'aria-hidden': 'true', style: 'font-size:24px' }),
        h('div', null, h('b', { text: 'مساعد Pharos الصحي' }), h('small', { text: 'أسئلة صحية • أدوية بدون وصفة • حجز مواعيد' })),
        h('button', { class: 'phc-x', type: 'button', 'aria-label': 'إغلاق', text: '✕', onclick: toggle })),
      msgs,
      h('div', { class: 'phc-foot' }, form,
        h('div', { class: 'phc-note', text: 'معلومات تعليمية فقط ومش بديل عن الدكتور. في الطوارئ اتصل بـ ' + CFG.emergencyNumber })));

    fab = h('button', { class: 'phc-fab', type: 'button', 'aria-label': 'افتح مساعد Pharos الصحي', 'aria-expanded': 'false', onclick: toggle, text: '💬' });
    root = h('div', { class: 'phc-root', dir: 'rtl' }, panel, fab);
    document.body.appendChild(root);

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state.open) toggle(); });
  }

  function toggle() {
    state.open = !state.open;
    root.classList.toggle('phc-open', state.open);
    fab.setAttribute('aria-expanded', String(state.open));
    fab.textContent = state.open ? '✕' : '💬';
    if (state.open) {
      if (!msgs.children.length) welcome();
      setTimeout(function () { input.focus(); }, 50);
    } else {
      fab.focus();
    }
  }

  function scrollDown() { msgs.scrollTop = msgs.scrollHeight; }

  function say(text, kind) {
    var m = h('div', { class: 'phc-m ' + (kind || 'bot') }, text);
    msgs.appendChild(m); scrollDown(); return m;
  }
  function block(el) {
    var w = h('div', { class: 'phc-block' }, el);
    msgs.appendChild(w); scrollDown(); return w;
  }
  function chips(items) {
    var wrap = h('div', { class: 'phc-chips' });
    items.forEach(function (it) {
      wrap.appendChild(h('button', { class: 'phc-chip', type: 'button', text: it.label, onclick: it.fn }));
    });
    return block(wrap);
  }
  function typing(on) {
    var old = document.getElementById('phc-typing');
    if (old) old.remove();
    if (on) {
      var t = h('div', { class: 'phc-m bot', id: 'phc-typing' }, h('div', { class: 'phc-typing' }, h('i'), h('i'), h('i')));
      msgs.appendChild(t); scrollDown();
    }
  }
  function apptButton(label) {
    return h('a', { class: 'phc-btn', href: CFG.appointmentsUrl, text: label || '📅 احجز موعد مع دكتور' });
  }

  // ───────────────────────── conversation ─────────────────────────
  function welcome() {
    say('أهلاً بيك في Pharos HIS 👋\nأنا مساعدك الصحي. أقدر:\n• أجاوب على أسئلتك الصحية\n• أرشحلك أدوية بدون وصفة المتاحة في أقرب صيدلية وتطلبها أونلاين\n• أوصلك لحجز موعد مع دكتور');
    quickChips();
  }
  function quickChips() {
    chips([
      { label: '🤒 عندي برد', fn: function () { ask('عندي برد'); } },
      { label: '🤕 صداع', fn: function () { ask('عندي صداع'); } },
      { label: '🤢 ألم في المعدة', fn: function () { ask('عندي الم في المعدة'); } },
      { label: '💊 عايز أطلب دوا', fn: function () { ask('عايز اطلب دوا'); } },
      { label: '📅 حجز موعد', fn: function () { ask('عايز احجز موعد'); } }
    ]);
  }
  function ask(text) { input.value = text; onUserSend(); }

  function onUserSend() {
    var text = input.value.trim();
    if (!text || state.busy) return;
    input.value = '';
    say(text, 'user');
    state.visits++;
    typing(true);
    state.busy = true;
    setTimeout(function () {
      typing(false);
      try { respond(text); } finally { state.busy = false; }
    }, 450);
  }

  function respond(raw) {
    var msg = norm(raw);

    if (hasAny(msg, SELF_HARM)) {
      say('أنا آسف إنك بتمر بوقت صعب كده، وكلامك مهم عندي. مش لازم تشيل ده لوحدك.\n' +
          'لو حاسس إنك ممكن تأذي نفسك دلوقتي، اتصل بالإسعاف على ' + CFG.emergencyNumber + ' أو روح أقرب مستشفى، وكلّم حد قريب منك يفضل معاك.\n' +
          'وتقدر كمان تحجز مع دكتور نفسي من صفحة المواعيد.', 'bot warn');
      block(h('div', { class: 'phc-row' }, apptButton('📅 احجز مع دكتور')));
      return;
    }
    if (hasAny(msg, RED_FLAGS)) {
      say('⚠️ الأعراض اللي ذكرتها ممكن تكون طارئة.\nاتصل بالإسعاف فوراً على ' + CFG.emergencyNumber + ' أو روح أقرب طوارئ دلوقتي، ومتستناش.', 'bot warn');
      return;
    }

    var topic = findTopic(msg);
    if (topic) { answerTopic(topic); return; }

    if (hasAny(msg, APPT)) {
      say('تقدر تحجز موعد مع دكتور من صفحة المواعيد، وتختار التخصص والوقت المناسب ليك.');
      block(h('div', { class: 'phc-row' }, apptButton()));
      return;
    }
    if (hasAny(msg, PHARM)) {
      say('تمام! قولي إيه اللي حاسس بيه (مثلاً: صداع، برد، حموضة، جرح) وأنا أرشحلك الأدوية المتاحة بدون وصفة في أقرب صيدلية.');
      chips([
        { label: 'صداع / ألم / حرارة', fn: function () { medFlow('pain'); } },
        { label: 'برد وكحة', fn: function () { medFlow('cold'); } },
        { label: 'حموضة / معدة', fn: function () { medFlow('stomach'); } },
        { label: 'جروح وإسعافات', fn: function () { medFlow('firstaid'); } },
        { label: 'فيتامينات', fn: function () { medFlow('vitamins'); } }
      ]);
      return;
    }
    if (hasWord(msg, GREET) && msg.split(' ').length <= 4) {
      say('أهلاً بيك! أقدر أساعدك إزاي النهاردة؟');
      quickChips();
      return;
    }
    say('مفهمتش سؤالك بالظبط. ممكن تقولي الأعراض أو الموضوع اللي عايز تسأل عنه؟ وأنا هنا أساعدك، ولو الموضوع محتاج دكتور أوصلك لحجز موعد.');
    quickChips();
  }

  function answerTopic(t) {
    var e = t.entry;
    say(e.response);
    var row = h('div', { class: 'phc-row' });
    if (e.medTopic) {
      row.appendChild(h('button', { class: 'phc-btn', type: 'button', text: '💊 شوف الأدوية المتاحة وأقرب صيدلية', onclick: function () { medFlow(e.medTopic); } }));
    } else if (e.needsDoctor) {
      say('الحالة دي أدويتها بتتصرف بوصفة من الدكتور، علشان كده مش بقدر أرشحلك دوا. الأفضل تتابع مع دكتور.');
    }
    if (e.needsDoctor || state.visits >= 3) row.appendChild(apptButton());
    if (row.children.length) block(row);
  }

  // ───────────────────────── medicines + pharmacies ─────────────────────────
  function medFlow(topicKey) {
    say('تمام. الأدوية دي بدون وصفة وبتتصرف حسب تعليمات الصيدلي. عايزني أحدد أقرب صيدلية ليك من موقعك؟');
    chips([
      { label: '📍 استخدم موقعي', fn: function () { say('استخدم موقعي', 'user'); locate(function () { loadMeds(topicKey); }); } },
      { label: 'من غير موقع', fn: function () { say('من غير موقع', 'user'); state.loc = null; loadMeds(topicKey); } }
    ]);
  }

  function locate(cb) {
    if (state.loc) return cb();
    if (!navigator.geolocation) { say('المتصفح مش بيدعم تحديد الموقع، هعرضلك الصيدليات المتاحة من غير ترتيب بالمسافة.'); return cb(); }
    say('هطلب إذن الموقع من المتصفح…');
    navigator.geolocation.getCurrentPosition(function (p) {
      state.loc = { lat: p.coords.latitude, lng: p.coords.longitude };
      cb();
    }, function () {
      say('مقدرتش أحدد موقعك. هعرضلك الصيدليات المتاحة من غير ترتيب بالمسافة.');
      cb();
    }, { timeout: 9000, maximumAge: 300000 });
  }

  function api(path, opts) {
    var ctrl = new AbortController();
    var t = setTimeout(function () { ctrl.abort(); }, 12000);
    var o = Object.assign({ credentials: 'same-origin', signal: ctrl.signal }, opts || {});
    return fetch(CFG.api + path, o).then(function (r) {
      return r.json().catch(function () { return { success: false, message: 'رد غير متوقع من الخادم.' }; });
    }).finally(function () { clearTimeout(t); });
  }

  function loadMeds(topicKey) {
    typing(true);
    var q = '?action=suggest&topic=' + encodeURIComponent(topicKey);
    if (state.loc) q += '&lat=' + state.loc.lat.toFixed(5) + '&lng=' + state.loc.lng.toFixed(5);
    api(q).then(function (res) {
      typing(false);
      if (!res.success) { say(res.message || 'حصلت مشكلة في تحميل الأدوية.'); return; }
      if (!res.medicines.length) {
        say('مفيش دوا متاح حالياً في الصيدليات لحالتك دي. اسأل أي صيدلي، أو احجز مع دكتور.');
        block(h('div', { class: 'phc-row' }, apptButton()));
        return;
      }
      say('دي الأدوية المتاحة بدون وصفة' + (res.located ? ' مرتبة بأقرب صيدلية ليك:' : ':') + '\n(الجرعة بيحددها الصيدلي أو الدكتور حسب حالتك.)');
      res.medicines.forEach(function (m) { block(medCard(m, res.located)); });
      say('لو الأعراض كملت أكتر من يومين أو زادت، الأفضل تشوف دكتور.');
      block(h('div', { class: 'phc-row' }, apptButton()));
    }).catch(function () {
      typing(false);
      say('معرفتش أوصل للخادم دلوقتي. حاول تاني بعد شوية.');
    });
  }

  function money(n) { return Number(n).toFixed(2) + ' ج.م'; }

  function medCard(m, located) {
    var card = h('div', { class: 'phc-card' },
      h('h4', { text: m.name_ar }),
      h('div', { class: 'sub', text: (m.strength ? m.strength + ' • ' : '') + money(m.unit_price) }));
    m.branches.forEach(function (b) {
      var dist = b.distance_km !== null && b.distance_km !== undefined ? b.distance_km + ' كم' : (located ? 'المسافة غير معروفة' : '');
      card.appendChild(h('div', { class: 'phc-br' },
        h('div', null,
          h('div', { class: 'n', text: '🏪 ' + b.name }),
          h('div', { class: 'd', text: [dist, b.address].filter(Boolean).join(' • ') })),
        h('button', { class: 'phc-btn', type: 'button', text: 'اطلب', onclick: function () { orderForm(m, b); } })));
    });
    return card;
  }

  // ───────────────────────── order form ─────────────────────────
  function orderForm(m, b) {
    say('طلب: ' + m.name_ar + ' من ' + b.name, 'user');
    var qty = h('input', { type: 'number', min: '1', max: String(Math.min(10, b.in_stock)), value: '1', inputmode: 'numeric', required: true });
    var name = h('input', { type: 'text', maxlength: '100', autocomplete: 'name', required: true });
    var phone = h('input', { type: 'tel', maxlength: '20', autocomplete: 'tel', inputmode: 'tel', required: true });
    var addr = h('textarea', { rows: '2', maxlength: '255', placeholder: 'العنوان بالتفصيل' });
    var addrWrap = h('label', { style: 'display:none' }, 'عنوان التوصيل', addr);
    var ful = h('select', { onchange: function () { addrWrap.style.display = ful.value === 'delivery' ? '' : 'none'; } },
      h('option', { value: 'pickup', text: 'استلام من الصيدلية' }),
      h('option', { value: 'delivery', text: 'توصيل للبيت' }));
    var err = h('div', { class: 'phc-err', role: 'alert' });
    var submit = h('button', { class: 'phc-btn', type: 'submit', text: '✅ تأكيد الطلب' });

    var form = h('form', { class: 'phc-form', novalidate: true, onsubmit: function (e) {
      e.preventDefault();
      err.textContent = '';
      var q = parseInt(qty.value, 10);
      if (!(q >= 1 && q <= Math.min(10, b.in_stock))) { err.textContent = 'الكمية المتاحة من 1 لـ ' + Math.min(10, b.in_stock) + '.'; return; }
      if (name.value.trim().length < 2) { err.textContent = 'اكتب اسمك.'; return; }
      if (!/^[0-9+\-\s]{8,20}$/.test(phone.value.trim())) { err.textContent = 'رقم التليفون مش صحيح.'; return; }
      if (ful.value === 'delivery' && addr.value.trim().length < 6) { err.textContent = 'اكتب عنوان التوصيل.'; return; }
      submit.disabled = true; submit.textContent = 'جاري الإرسال…';
      api('?action=order', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          branch_id: b.branch_id, name: name.value.trim(), phone: phone.value.trim(),
          fulfillment: ful.value, address: addr.value.trim(),
          items: [{ medicine_id: m.medicine_id, qty: q }]
        })
      }).then(function (res) {
        if (!res.success) { err.textContent = res.message || 'تعذر تسجيل الطلب.'; submit.disabled = false; submit.textContent = '✅ تأكيد الطلب'; return; }
        form.parentNode.removeChild(form);
        say('تم استلام طلبك ✅\nرقم الطلب: ' + res.order_number + '\nالصيدلية: ' + res.branch + '\nالإجمالي: ' + money(res.total) +
            '\nالصيدلية هتتصل بيك على ' + phone.value.trim() + ' لتأكيد الطلب، والدفع عند ' + (res.fulfillment === 'delivery' ? 'الاستلام من المندوب' : 'الاستلام من الصيدلية') + '.');
      }).catch(function () {
        err.textContent = 'معرفتش أوصل للخادم. حاول تاني.'; submit.disabled = false; submit.textContent = '✅ تأكيد الطلب';
      });
    } },
      h('label', null, 'الكمية', qty),
      h('label', null, 'الاسم', name),
      h('label', null, 'رقم التليفون', phone),
      h('label', null, 'طريقة الاستلام', ful),
      addrWrap,
      h('div', { class: 'phc-note', style: 'text-align:start', text: 'بنستخدم بياناتك لتنفيذ الطلب فقط. الطلب مش نهائي لحد ما الصيدلية تأكده معاك.' }),
      err,
      h('div', { class: 'phc-row' }, submit));
    [qty, name, phone, addr, ful].forEach(function (el) { el.addEventListener('input', function () { err.textContent = ''; }); });
    block(h('div', { class: 'phc-card' }, h('h4', { text: m.name_ar + ' — ' + b.name }), form));
    name.focus();
  }

  // ───────────────────────── boot ─────────────────────────
  function boot() { if (!document.body) return; build(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
