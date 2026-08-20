<?php

/*
 * Arabic validation messages.
 *
 * Only the rules this project actually uses are translated; anything missing
 * falls back to the English file, so adding a rule later degrades to English
 * rather than showing a raw key.
 */
return [
    'accepted' => 'يجب الموافقة على :attribute.',
    'after' => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'alpha' => 'يجب أن يحتوي :attribute على أحرف فقط.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'date' => 'يجب أن يكون :attribute تاريخاً صحيحاً.',
    'digits' => 'يجب أن يكون :attribute مكوناً من :digits أرقام.',
    'email' => 'يجب أن يكون :attribute بريداً إلكترونياً صحيحاً.',
    'enum' => 'القيمة المختارة في :attribute غير صحيحة.',
    'exists' => 'القيمة المختارة في :attribute غير موجودة.',
    'in' => 'القيمة المختارة في :attribute غير صحيحة.',
    'integer' => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'numeric' => 'يجب أن يكون :attribute رقماً.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'size' => [
        'string' => 'يجب أن يكون :attribute :size أحرف.',
        'numeric' => 'يجب أن يكون :attribute مساوياً :size.',
    ],
    'string' => 'يجب أن يكون :attribute نصاً.',
    'unique' => ':attribute مستخدم مسبقاً.',
    'max' => [
        'string' => 'يجب ألا يزيد :attribute عن :max حرفاً.',
        'numeric' => 'يجب ألا يزيد :attribute عن :max.',
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصراً.',
    ],
    'min' => [
        'string' => 'يجب أن يكون :attribute :min أحرف على الأقل.',
        'numeric' => 'يجب أن يكون :attribute :min على الأقل.',
        'array' => 'يجب أن يحتوي :attribute على :min عناصر على الأقل.',
    ],

    /*
     * Password policy of BRD FR-SEC-03.
     */
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'كلمة المرور هذه ظهرت في تسريبات بيانات، الرجاء اختيار غيرها.',
    ],

    /*
     * Field labels, so messages read naturally instead of echoing column names.
     */
    'attributes' => [
        'name' => 'اسم المحل',
        'trade_name' => 'الاسم التجاري',
        'commercial_register' => 'رقم السجل التجاري',
        'owner_name' => 'اسم المالك',
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الموبايل',
        'city' => 'المدينة',
        'currency' => 'العملة',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'accepts_terms' => 'شروط الخدمة',
        'accepts_data_processing' => 'اتفاقية معالجة البيانات',
        'code' => 'رمز التحقق',
        'invoice_number' => 'رقم الفاتورة',
        'amount' => 'قيمة الفاتورة',
        'invoice_date' => 'تاريخ الفاتورة',
        'customer_id' => 'الزبون',
        'consent_given' => 'موافقة الزبون',
        'threshold_amount' => 'قيمة العتبة',
        'threshold_invoice_count' => 'عدد الفواتير المطلوب',
        'reward_value' => 'قيمة المكافأة',
        'max_discount_amount' => 'الحد الأقصى للحسم',
        'min_invoice_amount' => 'الحد الأدنى للفاتورة',
        'effective_from' => 'تاريخ السريان',
        'role' => 'الدور',
        'branch_id' => 'الفرع',
        'address' => 'العنوان',
        'reason' => 'السبب',
        'subscription_plan_id' => 'خطة الاشتراك',
        'subscription_ends_at' => 'تاريخ انتهاء الاشتراك',
        'device_name' => 'اسم الجهاز',
    ],
];
