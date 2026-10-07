// Health Q&A Chatbot in Egyptian Colloquial Arabic
class HealthChatbot {
    constructor() {
        this.knowledgeBase = this.initializeKnowledgeBase();
        this.medicationDatabase = this.initializeMedicationDatabase();
        this.conversationHistory = [];
        this.visitCount = 0;
    }

    initializeMedicationDatabase() {
        return {
            "البرد": [
                "💊 بنسيللين (مضاد حيوي)",
                "💊 باراسيتامول (خافض حرارة ومسكن)",
                "💊 ديكسترومثورفان (معطل للكحة)",
                "💊 محاليل الأنف (مرطبات)"
            ],
            "الانفلونزا": [
                "💊 تاميفلو (مضاد فيروسي)",
                "💊 باراسيتامول (خافض حرارة)",
                "💊 إيبوبروفين (مسكن ومضاد التهاب)",
                "💊 ريلينزا (مضاد فيروسي)"
            ],
            "الصداع": [
                "💊 باراسيتامول (أسيتامينوفين)",
                "💊 إيبوبروفين (أدفيل)",
                "💊 نابروكسين",
                "💊 أسبرين"
            ],
            "الشقيقة": [
                "💊 سوماتريبتان (Sumatriptan)",
                "💊 زولميتريبتان (Zolmitriptan)",
                "💊 إيبوبروفين عالي الجرعة",
                "💊 الأرغوتامين"
            ],
            "آلام المعدة": [
                "💊 أوميبرازول (مثبط حمض)",
                "💊 رانيتيدين",
                "💊 ميترونيدازول (للعدوى البكتيرية)",
                "💊 أدوية مهدئة للمعدة"
            ],
            "الإمساك": [
                "💊 بيساكوديل",
                "💊 سينا",
                "💊 دوكوسات الصوديوم",
                "💊 لاكتولوز (ملين طبيعي)"
            ],
            "الأرق": [
                "💊 ميلاتونين",
                "💊 ديفينهيدرامين",
                "💊 فاليريان",
                "💊 عشبة الناردين"
            ],
            "السكري": [
                "💊 الأنسولين",
                "💊 ميتفورمين",
                "💊 جليبيزيد",
                "💊 سيتاجليبتين"
            ]
        };
    }

    initializeKnowledgeBase() {
        return {
            // Cold & Flu
            "البرد": {
                keywords: ["برد", "زكام", "رشح", "كحة", "حمى"],
                response: "البرد الشديد ممكن نتعامل معاه بـ:\n✓ نوم كويس والراحة\n✓ اشرب سوايل دافية مثل الشاي والعسل\n✓ استعمل مرطبات الأنف\n✓ لو الأعراض استمرت أكتر من أسبوع روح للدكتور"
            },
            "الانفلونزا": {
                keywords: ["انفلونزا", "فلو", "حمى شديدة", "ألم في العضلات"],
                response: "الإنفلونزا بتحتاج:\n✓ اعزل نفسك عشان ما تعدي الناس التانية\n✓ اشرب سوايل وتناول طعام صحي\n✓ خذ راحتك والنم\n✓ إذا الحرارة عالية تقدر تاخد خوافض حرارة (البراسيتامول)\n⚠️ لو ساعت اتصل بالدكتور فوراً"
            },

            // Headache & Migraine
            "الصداع": {
                keywords: ["صداع", "وجع الراس", "راس"],
                response: "للصداع العادي:\n✓ اشرب مية برد\n✓ استرخي في مكان هادي\n✓ لو الصداع مستمر تقدر تاخد مسكن آمن\n✓ تقليل الكافيين والمنبهات\n⚠️ لو الصداع شديد جداً أو مصحوب بأعراض تانية استشير دكتور"
            },
            "الشقيقة": {
                keywords: ["شقيقة", "ميجرين", "ألم نصف الراس", "دوخة مع صداع"],
                response: "الشقيقة (الميجرين) بتحتاج:\n✓ اعزل نفسك في مكان هادي بلا أصوات\n✓ اطفي الأضواء القوية\n✓ اشرب مية\n✓ استرخي وحاول تنام\n✓ استعمل مسكنات متخصصة للشقيقة\n🏥 لو متكررة استشير دكتور متخصص"
            },

            // Stomach Issues
            "آلام المعدة": {
                keywords: ["معدة", "بطن", "ألم في البطن", "انتفاخ", "غازات"],
                response: "لآلام المعدة:\n✓ تجنب الأكل الدهني والحار\n✓ اشرب مية بدفء ببطء\n✓ جرب الزنجبيل أو البابونج\n✓ تناول طعام خفيف وسهل الهضم\n✓ قلل من القهوة والمنبهات\n⚠️ لو الألم مستمر أكتر من يومين روح للدكتور"
            },
            "الإمساك": {
                keywords: ["إمساك", "صعوبة في التبرز", "إسهال"],
                response: "للإمساك:\n✓ اشرب مية كتير - 8 أكواب في اليوم على الأقل\n✓ كل ألياف أكتر (خضار وفاكهة)\n✓ حرك نفسك وتمرن\n✓ لا تتأخر لما تحس بالرغبة\n✓ شاي الينسون أو السنا مفيد\n🏥 لو استمر أكتر من أسبوع اتصل بالدكتور"
            },

            // Sleep Issues
            "الأرق": {
                keywords: ["أرق", "ما أقدر أنام", "نوم سيء", "مشاكل النوم"],
                response: "للأرق والنوم السيء:\n✓ حافظ على روتين نوم منتظم\n✓ نام بوقت واحد كل يوم\n✓ بلاش كافيين قبل النوم بـ 4-5 ساعات\n✓ غرفتك تكون هادية وباردة\n✓ تجنب الموبايل قبل النوم بنص ساعة\n✓ مارس رياضة أثناء النهار\n💆 تجربة الاسترخاء والتأمل مفيدة"
            },

            // Diabetes
            "السكري": {
                keywords: ["سكري", "السكر", "مرض السكر", "السكر في الدم"],
                response: "السكري بيحتاج متابعة:\n✓ التزم بالحمية - قلل السكريات البيضاء\n✓ تناول أطعمة صحية كاملة الدسم\n✓ تمرن يومياً لو 30 دقيقة\n✓ قيس السكر بانتظام\n✓ خذ الأدوية بانتظام زي ما قال الدكتور\n✓ اهتم بصحتك النفسية\n🏥 لازم تكون تحت إشراف دكتور متخصص\n\n💡 اكتب 'السكري والتغذية' عشان تعرف الحمية المفصلة للسكري"
            },

            // Diabetes & Nutrition
            "السكري والتغذية": {
                keywords: ["سكري غذاء", "سكري تغذية", "سكري حمية", "سكري أكل", "نظام غذائي سكري", "تغذية السكري"],
                response: "📋 نصائح هامة للتغذية الصحية لمصاحبي السكر\n\n✅ التوصيات الموصى بها:\n\n🔹 الاهتمام بمواعيد الوجبات وضبطها مع العلاج حسب توصية الطبيب\n\n🔹 البد أن تكون الوجبة مكتملة العناصر الغذائية: نشويات – بروتين – خضراوات – دهون صحية – منتجات ألبان\n\n🔹 الحرص على وجود خضراوات السالطة سواء مقطعة أو سليمة في كل الوجبات:\nطماطم – خيار – بصل – فلفل – خس – جزر – فجل – بنجر – خضرة (جرجير – بقدونس – كسبرة)\n\n🔹 الحرص على وجود البروتين في كل الوجبات حسب الذوق والإمكانيات:\nالبيض – البقوليات (الفول – العدس – الحمص – الفاصولياء – اللوبيا) – الجبن والحليب (القريش – جبن قليلة الدهون – الرايب – الزبادى) – التونة – الأسماك – الطيور بدون الجلد – اللحوم الحمراء قليلة الدهن – الفطر أو الماشروم\n\n🔹 يفضل استخدام النشويات الآتية - الحبوب الكاملة:\nدقيق القمح الكامل (به ردة) – الشوفان – البليلة – البرغل – الفريك\n\n🔹 الأرز البني – المكرونة البنية\n\n🔹 ثمرة بطاطس أو بطاطا في حجم قبضة اليد\n\n🔹 نكتفي برغيف بلدى صغير أو 2 توست بني أو 5-4 معلق أرز أو مكرونة بني\n\n🔹 الاكثار من الخضار المطبوخ الغير نشوي:\nكوسة – باذنجان – فاصوليا خضراء – سبانخ – بامية – ملوخية – البروكلى – القرنبيط\n\n🔹 عند طبخ القلقاس والبطاطس والبسلة نراعي تقليل النشويات معهم\n\n🍎 الفاكهة:\nثمرتان فاكهة في اليوم في حجم قبضة اليد:\nتفاح – موز – برتقال – جوافة – يوسفي – مانجو – كيوي\n\nأو مج كبير من: مكعبات البطيخ – الكنتالوب – الاناناس – الفراولة\n\nأو 10 عنبات أو 3 تمر – بلح أو 2 تين أو 3 مشمش\n\n🫒 استخدام الدهون الصحية في الأكل:\nزيت الزيتون – السمن البلدى (ملعقة صغيرة في الطبخ) – زبدة الفول السوداني (بدون سكر أو زيوت مهدرجة) – المكسرات النية الغير المحمصة الغير مملحة – القليل من زيت عباد الشمس وزيت الذرة\n\n🚫 للتحلية: لا يزيد استخدام السكر الأبيض في اليوم عن ملعقة ونصف\n\n💧 الحرص على شرب 8 كوب ماء مقسمين على مدار اليوم\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n🚫 الممنوعات (الأطعمة التي يجب تجنبها):\n\n❌ التدخين\n\n❌ المعلبات المحالة بالفركتوز الصناعي المكتوب عليها (لايت أو دايت)\n\n❌ المربى والحلاوة الطحينية\n\n❌ علب العصائر حتى التي مكتوب عليها بدون سكر\n\n❌ الدهون المهدرجة مثل السمن والزبد الصناعي ومخبوزات وكيك الأفران\n\n❌ اللحوم المصنعة مثل الالنشون والسوسيس والبلوبيف\n\n❌ مرقة الدجاج المصنعة والصلصة المحفوظة\n\n❌ أكياس التسالي مثل الشيبسي والبسكوت\n\n❌ الحلويات الشرقية والغربية\n\n❌ الوجبات السريعة\n\n❌ المياه الغازية\n\n❌ الخمور\n\n🏥 استشير الدكتور دائماً قبل عمل أي تغييرات على حميتك"
            },

            // High Blood Pressure
            "ضغط الدم": {
                keywords: ["ضغط دم", "ضغط مرتفع", "ضغط منخفض", "دوار"],
                response: "لضغط الدم المرتفع:\n✓ قلل الملح في الطعام\n✓ تمرن بانتظام (مشي 30 دقيقة)\n✓ قلل الدهون والأطعمة الدسمة\n✓ قلل الكافيين والتدخين\n✓ انتظم على الأدوية\n✓ استرخي وقلل التوتر\n⚠️ قيس ضغطك بانتظام والتزم بالدكتور"
            },

            // Anxiety & Stress
            "القلق": {
                keywords: ["قلق", "توتر", "ضغط عصبي", "أرق من الخوف"],
                response: "للقلق والتوتر:\n✓ تنفس بهدوء - تنفس عميق من الأنف لمدة 4 ثواني\n✓ مارس اليوجا أو التأمل\n✓ ابتعد عن مصادر التوتر لو أمكن\n✓ تحدث مع حد ثقة فيهم\n✓ مارس الرياضة\n✓ اشرب شاي ألعشاب مهدئة (ليمون بالعسل)\n🏥 لو القلق شديد شوف دكتور نفسي"
            },

            // Skin Issues
            "الجلد": {
                keywords: ["جلد", "حكة", "طفح جلدي", "حساسية جلد", "حب الشباب"],
                response: "مشاكل الجلد:\n✓ اغسل الوش بمية دافية وصابون لطيف\n✓ رطب جلدك بعد الغسيل\n✓ تجنب المنتجات القاسية\n✓ لا تخش الجلد إذا كانت حكة\n✓ ابعد عن المواد المسببة للحساسية\n⚠️ لو الحالة سيئة استشير دكتور جلدية"
            },

            // General Health Tips
            "النصائح الصحية": {
                keywords: ["نصيحة صحية", "نصائح", "عيش صحي", "حياة صحية"],
                response: "نصائح صحية عامة:\n✓ اشرب مية كتير يومياً (8 أكواب)\n✓ نام من 7-8 ساعات يومياً\n✓ مارس رياضة 30 دقيقة يومياً\n✓ كل خضار وفاكهة يومياً\n✓ قلل الملح والسكريات\n✓ ما تدخن ولا تشرب كحول\n✓ اعمل فحوصات دورية\n✓ ركز على الصحة النفسية"
            },

            // Vaccines
            "التطعيمات": {
                keywords: ["تطعيم", "لقاح", "كورونا", "شلل", "حصبة"],
                response: "التطعيمات مهمة جداً:\n✓ اللقاحات تحميك من أمراض خطيرة\n✓ اتبع جدول التطعيمات الموصى به\n✓ خذ التطعيمات في الوقت المناسب\n✓ اسأل الدكتور عن أي آثار جانبية\n✓ احتفظ برقم التطعيمات\n✓ لا تقلق من الآثار الجانبية البسيطة\n🏥 استشير دكتور قبل أي تطعيم لو عندك مشاكل صحية"
            },

            // Nutrition
            "التغذية": {
                keywords: ["أكل", "غذاء", "تغذية", "بروتين", "كالسيوم", "فيتامينات"],
                response: "التغذية السليمة:\n✓ كل بروتين (دجاج، سمك، بيض، بقول)\n✓ اشمل الخضار والفاكهة في كل وجبة\n✓ اشرب لبن ومشتقاته (كالسيوم)\n✓ كل حبوب كاملة بدل البيضاء\n✓ قلل السكريات والدهون الضارة\n✓ ما تتخطاش الفطور\n✓ اشرب مية كتير"
            },

            // Exercise
            "الرياضة": {
                keywords: ["رياضة", "تمرين", "جيم", "مشي", "اللياقة"],
                response: "الرياضة والحركة:\n✓ اعمل رياضة متوازنة (قلب وقوة ومرونة)\n✓ ابدأ تدريجياً لا تعمل نفسك\n✓ مشي 30 دقيقة يومياً\n✓ سباحة بتركيزية رائعة\n✓ اليوجا والاسترخاء مفيدة\n✓ مارس رياضة تحبها\n✓ احمي نفسك من الاصابات\n💪 الاستمرارية أهم من الشدة"
            }
        };
    }

    findAnswer(userMessage) {
        const message = userMessage.trim().toLowerCase();
        this.visitCount++;
        
        // Search through knowledge base
        for (const [key, data] of Object.entries(this.knowledgeBase)) {
            for (const keyword of data.keywords) {
                if (message.includes(keyword)) {
                    let response = data.response;
                    
                    // Add medications if available
                    if (this.medicationDatabase[key]) {
                        response += "\n\n💊 **أدوية موصى بها:**\n";
                        response += this.medicationDatabase[key].join("\n");
                        response += "\n\n⚠️ تذكر: استشير الدكتور قبل تناول أي دواء!";
                    }
                    
                    // After 3 interactions, suggest doctor consultation
                    if (this.visitCount >= 3) {
                        response += "\n\n🏥 **لو أعراضك مستمرة أو ما تتحسن، تقدر تحجز موعد مع دكتور أونلاين من هنا:**\n📞 <a href='Appointments Page.html' style='color: #0a4d8c; font-weight: bold;'>احجز موعد الآن</a>";
                    }
                    
                    return response;
                }
            }
        }

        // If no match found, return a default response
        return this.getDefaultResponse(userMessage);
    }

    getDefaultResponse(userMessage) {
        const responses = [
            "أنا عندي معلومات عن: البرد، الإنفلونزا، الصداع، آلام المعدة، السكري (مع نصائح تغذية مفصلة)، ضغط الدم، القلق، النوم، الجلد، التطعيمات، والتغذية والرياضة. اسأل عن حد من دول!",
            "ما فهمت سؤالك بالظبط. ممكن تسأل عن مشكلة صحية معينة؟ أنا هنا عشان أساعدك. اكتب 'السكري والتغذية' للحصول على نصائح غذائية مفصلة للسكري.",
            "لو عندك مشكلة صحية خطيرة أو مستمرة روح للدكتور فوراً. أنا بوت تعليمي بس.",
            "اسأل عن أي حاجة متعلقة بالصحة والطب، وأنا هساعدك بأفضل معلومات. جرب اكتب 'السكري والتغذية' لنصائح غذائية شاملة."
        ];
        
        return responses[Math.floor(Math.random() * responses.length)];
    }
}

// Initialize chatbot
const chatbot = new HealthChatbot();

// DOM Elements
const chatMessagesDiv = document.getElementById('chatMessages');
const userInput = document.getElementById('userInput');
const sendBtn = document.getElementById('sendBtn');

// Send message function
function sendMessage() {
    const message = userInput.value.trim();
    
    if (message === '') return;

    // Add user message to chat
    addMessageToChat(message, 'user');
    
    // Clear input
    userInput.value = '';
    
    // Show typing indicator
    showTypingIndicator();
    
    // Simulate bot thinking time (500ms)
    setTimeout(() => {
        removeTypingIndicator();
        
        // Get bot response
        const response = chatbot.findAnswer(message);
        
        // Add bot response to chat
        addMessageToChat(response, 'bot');
    }, 500);
}

// Add message to chat display
function addMessageToChat(message, sender) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${sender}`;
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    
    // Support HTML for links and formatting
    if (sender === 'bot' && message.includes('<a')) {
        contentDiv.innerHTML = message;
    } else {
        contentDiv.textContent = message;
    }
    
    messageDiv.appendChild(contentDiv);
    chatMessagesDiv.appendChild(messageDiv);
    
    // Scroll to bottom
    chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight;
}

// Show typing indicator
function showTypingIndicator() {
    const messageDiv = document.createElement('div');
    messageDiv.className = 'message bot';
    messageDiv.id = 'typingIndicator';
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'typing-indicator';
    contentDiv.innerHTML = '<div class="dot"></div><div class="dot"></div><div class="dot"></div>';
    
    messageDiv.appendChild(contentDiv);
    chatMessagesDiv.appendChild(messageDiv);
    
    chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight;
}

// Remove typing indicator
function removeTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) {
        indicator.remove();
    }
}

// Event listeners
sendBtn.addEventListener('click', sendMessage);

userInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        sendMessage();
    }
});

// Focus on input when page loads
window.addEventListener('load', () => {
    userInput.focus();
});
