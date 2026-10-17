<?php

namespace Database\Seeders\Kenya;

/**
 * Demo data for "Law Management System (Law)": three Kenyan law firms with realistic people, clients, matters, hearings and
 * invoices. The names, companies, phone numbers and case numbers are invented for demonstration (no real person, firm or
 * court file is meant); the courts, case types, case-number formats, M-Pesa and VAT at 16% are the real Kenyan ones.
 *
 * Dates are written as offsets from the day the data is created ("-140" = 140 days ago), so the diary always has hearings
 * coming up and the dashboards are never empty.
 */
final class KenyaDemoData
{
    /** The platform super admin (override with LAW_SUPERADMIN_EMAIL / LAW_SUPERADMIN_PASSWORD). */
    public static function superAdmin(): array
    {
        return [
            'name' => 'Super Admin',
            'email' => (string) env('LAW_SUPERADMIN_EMAIL', 'superadmin@law.co.ke'),
            'password' => (string) env('LAW_SUPERADMIN_PASSWORD', 'Law@Admin#2026'),   // 12+ characters: the platform's own rule
            'role' => 'admin',
        ];
    }

    /** Courts beyond the system catalog (which has the superior courts and the main Nairobi stations): name => court type key. */
    public static function extraCourts(): array
    {
        return [
            'High Court at Mombasa' => 'high_court',
            'Environment and Land Court at Mombasa' => 'environment_land_court',
            "Chief Magistrate's Court at Mombasa" => 'magistrate_court',
            "Kadhi's Court at Mombasa" => 'kadhis_court',
            'High Court at Eldoret' => 'high_court',
            'Environment and Land Court at Eldoret' => 'environment_land_court',
            "Chief Magistrate's Court at Eldoret" => 'magistrate_court',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function firms(): array
    {
        return [self::wanjiruOtieno(), self::coastLegal(), self::riftValley()];
    }

    private static function wanjiruOtieno(): array
    {
        $milimaniHC = 'High Court at Nairobi (Milimani Law Courts)';
        $elc = 'Environment and Land Court at Nairobi';
        $elrc = 'Employment and Labour Relations Court at Nairobi';
        $cmc = "Chief Magistrate's Court, Milimani";

        return [
            'slug' => 'wanjiru-otieno',
            'name' => 'Wanjiru & Otieno Advocates',
            'domain' => 'localhost',          // the firm you reach at plain http://localhost:<port>
            'extra_domains' => ['127.0.0.1'],
            'plan' => 'enterprise',
            'subscription' => ['plan' => 'enterprise', 'paid' => true],
            'color' => '#0f5c8c',
            'letterhead' => [
                'address' => '3rd Floor, Kilimani Business Centre, Argwings Kodhek Road, Nairobi',
                'phone' => '+254 20 555 0142', 'email' => 'info@wanjiruotieno.co.ke', 'website' => 'www.wanjiruotieno.co.ke', 'tax_pin' => 'P051234567K',
            ],
            'invoice_footer' => 'Payment terms: 14 days. Pay by M-Pesa Paybill (number on request), account: invoice number. Bank: Equity Bank, Prestige Branch, A/C 0110 2938 4756 0. All fees are subject to VAT at 16%.',
            'users' => [
                ['name' => 'Grace Wanjiru Kamau', 'email' => 'grace.kamau@wanjiruotieno.co.ke', 'role' => 'admin', 'title' => 'Managing Partner', 'phone' => '0700100101', 'hired' => '2012-03-01', 'password' => 'Law@Grace2026!'],
                ['name' => 'David Otieno Odhiambo', 'email' => 'david.odhiambo@wanjiruotieno.co.ke', 'role' => 'advocate', 'title' => 'Partner, Litigation', 'phone' => '0700100102', 'hired' => '2014-06-01', 'password' => 'Law@David2026!', 'rate' => 2500000],
                ['name' => 'Amina Hassan Abdi', 'email' => 'amina.abdi@wanjiruotieno.co.ke', 'role' => 'advocate', 'title' => 'Senior Associate, Commercial', 'phone' => '0700100103', 'hired' => '2018-02-15', 'password' => 'Law@Amina2026!', 'rate' => 1800000],
                ['name' => 'Peter Kiprono Rotich', 'email' => 'peter.rotich@wanjiruotieno.co.ke', 'role' => 'advocate', 'title' => 'Associate, Land and Conveyancing', 'phone' => '0700100104', 'hired' => '2021-09-01', 'password' => 'Law@Peter2026!', 'rate' => 1200000],
                ['name' => 'Mercy Achieng Onyango', 'email' => 'mercy.onyango@wanjiruotieno.co.ke', 'role' => 'advocate', 'title' => 'Associate, Family and Succession', 'phone' => '0700100105', 'hired' => '2022-01-10', 'password' => 'Law@Mercy2026!', 'rate' => 1200000],
                ['name' => 'Brian Mutua Musyoka', 'email' => 'brian.musyoka@wanjiruotieno.co.ke', 'role' => 'clerk', 'title' => 'Court Clerk', 'phone' => '0700100106', 'hired' => '2023-04-03', 'password' => 'Law@Brian2026!'],
                ['name' => 'Faith Njeri Gathoni', 'email' => 'faith.gathoni@wanjiruotieno.co.ke', 'role' => 'clerk', 'title' => 'Accounts Clerk', 'phone' => '0700100107', 'hired' => '2020-11-16', 'password' => 'Law@Faith2026!'],
            ],
            'clients' => [
                'njoroge' => ['name' => 'Joseph Kamau Njoroge', 'phone' => '0700200201', 'extra' => '0110200201', 'address' => 'Ndenderu, Kiambu County', 'advocate' => 'david.odhiambo@wanjiruotieno.co.ke'],
                'safari' => ['name' => 'Safari Fresh Produce Ltd', 'phone' => '0700200202', 'extra' => null, 'address' => 'Enterprise Road, Industrial Area, Nairobi', 'advocate' => 'amina.abdi@wanjiruotieno.co.ke'],
                'zawadi' => ['name' => 'Zawadi Mwikali Muthama', 'phone' => '0700200203', 'extra' => null, 'address' => 'Mwala Township, Machakos County', 'advocate' => 'peter.rotich@wanjiruotieno.co.ke'],
                'kilimani' => ['name' => 'Kilimani Heights Residents Association', 'phone' => '0700200204', 'extra' => '0700200214', 'address' => 'Wood Avenue, Kilimani, Nairobi', 'advocate' => 'amina.abdi@wanjiruotieno.co.ke'],
                'nyanza' => ['name' => 'Nyanza Cooperative Sacco Society Ltd', 'phone' => '0700200205', 'extra' => null, 'address' => 'Oginga Odinga Street, Kisumu', 'advocate' => 'peter.rotich@wanjiruotieno.co.ke'],
                'noor' => ['name' => 'Ibrahim Ali Noor', 'phone' => '0700200206', 'extra' => null, 'address' => '12th Street, Eastleigh, Nairobi', 'advocate' => 'david.odhiambo@wanjiruotieno.co.ke'],
                'lakeview' => ['name' => 'Lakeview Hotels (Kenya) Ltd', 'phone' => '0700200207', 'extra' => '0700200217', 'address' => 'Moi South Lake Road, Naivasha', 'advocate' => 'amina.abdi@wanjiruotieno.co.ke'],
                'simiyu' => ['name' => 'Estate of the Late Samuel Wafula Simiyu (through Rose Nekesa Simiyu)', 'phone' => '0700200208', 'extra' => null, 'address' => 'Kanduyi, Bungoma County', 'advocate' => 'mercy.onyango@wanjiruotieno.co.ke'],
            ],
            'matters' => [
                [
                    'key' => 'm1', 'client' => 'njoroge', 'lawyer' => 'david.odhiambo@wanjiruotieno.co.ke', 'number' => 'HCCC E214 of 2026', 'type' => 'civil', 'court' => $milimaniHC, 'start' => -140,
                    'description' => 'Claim for KSh 4.8 million for breach of contract and non-delivery of building materials.', 'opposing' => 'Savannah Builders Ltd', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -95, 'type' => 'Mention', 'time' => '09:00', 'room' => 'Court 7', 'officer' => 'Deputy Registrar', 'notes' => 'Parties confirmed filing of pleadings.', 'outcome' => 'Defence filed; pre-trial directions set.'],
                        ['offset' => -30, 'type' => 'Pretrial Conference', 'time' => '09:30', 'room' => 'Court 7', 'officer' => 'Deputy Registrar', 'notes' => 'Compliance with Order 11 checked.', 'outcome' => 'Compliance confirmed; matter set down for hearing.'],
                        ['offset' => 6, 'type' => 'Trial', 'time' => '09:00', 'room' => 'Court 7', 'officer' => 'Hon. Lady Justice, Civil Division', 'notes' => 'Plaintiff to call two witnesses.'],
                    ],
                ],
                [
                    'key' => 'm2', 'client' => 'zawadi', 'lawyer' => 'peter.rotich@wanjiruotieno.co.ke', 'number' => 'ELC Case 087 of 2025', 'type' => 'land', 'court' => $elc, 'start' => -300,
                    'description' => 'Boundary dispute and cancellation of title Machakos/Mwala/3412, obtained irregularly.', 'opposing' => 'Mutiso Mwanzia and the Land Registrar, Machakos', 'station' => 'Mwala',
                    'hearings' => [
                        ['offset' => -120, 'type' => 'Case Management conference', 'time' => '10:00', 'room' => 'ELC Court 2', 'officer' => 'Hon. Judge, ELC', 'notes' => 'Directions on site visit.', 'outcome' => 'Surveyor to file report within 45 days.'],
                        ['offset' => 12, 'type' => 'Evidence hearing', 'time' => '10:00', 'room' => 'ELC Court 2', 'officer' => 'Hon. Judge, ELC', 'notes' => 'County surveyor to testify.'],
                    ],
                ],
                [
                    'key' => 'm3', 'client' => 'safari', 'lawyer' => 'amina.abdi@wanjiruotieno.co.ke', 'number' => 'CMCC E1876 of 2026', 'type' => 'commercial', 'court' => $cmc, 'start' => -90,
                    'description' => 'Recovery of KSh 1.2 million in unpaid transport and cold-chain charges.', 'opposing' => 'Rift Haulage Services Ltd', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -40, 'type' => 'Mention', 'time' => '08:30', 'room' => 'Court 12', 'officer' => 'Chief Magistrate', 'notes' => 'Defendant sought time to respond.', 'outcome' => 'Defence due in 14 days.'],
                        ['offset' => 3, 'type' => 'Interlocutory Application', 'time' => '08:30', 'room' => 'Court 12', 'officer' => 'Chief Magistrate', 'notes' => 'Application for summary judgment.'],
                    ],
                ],
                [
                    'key' => 'm4', 'client' => 'lakeview', 'lawyer' => 'amina.abdi@wanjiruotieno.co.ke', 'number' => 'ELRC Cause 312 of 2025', 'type' => 'labour and employment', 'court' => $elrc, 'start' => -200,
                    'description' => 'Claim by a former head chef for unfair termination and unpaid overtime.', 'opposing' => 'Moses Kipkemboi Langat', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -60, 'type' => 'Evidence hearing', 'time' => '11:00', 'room' => 'ELRC Court 1', 'officer' => 'Hon. Judge, ELRC', 'notes' => 'Claimant testified.', 'outcome' => 'Respondent to open its case.'],
                        ['offset' => 20, 'type' => 'Evidence hearing', 'time' => '11:00', 'room' => 'ELRC Court 1', 'officer' => 'Hon. Judge, ELRC', 'notes' => 'HR manager to testify for the respondent.'],
                    ],
                ],
                [
                    'key' => 'm5', 'client' => 'simiyu', 'lawyer' => 'mercy.onyango@wanjiruotieno.co.ke', 'number' => 'Succession Cause E089 of 2025', 'type' => 'family', 'court' => $milimaniHC, 'start' => -260,
                    'description' => 'Petition for letters of administration intestate; objection by a second family.', 'opposing' => 'Beatrice Namukhula Simiyu (Objector)', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -75, 'type' => 'Mention', 'time' => '09:00', 'room' => 'Family Division', 'officer' => 'Hon. Lady Justice, Family Division', 'notes' => 'Objector served.', 'outcome' => 'Parties to file affidavits of protest.'],
                        ['offset' => 9, 'type' => 'Hearing of Substantive applications', 'time' => '09:00', 'room' => 'Family Division', 'officer' => 'Hon. Lady Justice, Family Division', 'notes' => 'Hearing of the protest.'],
                    ],
                ],
                [
                    'key' => 'm6', 'client' => 'noor', 'lawyer' => 'david.odhiambo@wanjiruotieno.co.ke', 'number' => 'Criminal Case E1203 of 2026', 'type' => 'criminal', 'court' => $cmc, 'start' => -60,
                    'description' => 'Defence on a charge of obtaining credit by false pretences (section 316, Penal Code).', 'opposing' => 'Republic (Office of the Director of Public Prosecutions)', 'station' => 'Kilimani',
                    'hearings' => [
                        ['offset' => -58, 'type' => 'Plea Taking', 'time' => '09:00', 'room' => 'Court 4', 'officer' => 'Principal Magistrate', 'notes' => 'Plea of not guilty entered.', 'outcome' => 'Released on cash bail of KSh 100,000.'],
                        ['offset' => 2, 'type' => 'Pretrial Conference', 'time' => '09:00', 'room' => 'Court 4', 'officer' => 'Principal Magistrate', 'notes' => 'Disclosure of prosecution witness statements.'],
                    ],
                ],
                [
                    'key' => 'm7', 'client' => 'kilimani', 'lawyer' => 'amina.abdi@wanjiruotieno.co.ke', 'number' => 'Petition E067 of 2026', 'type' => 'constitutional', 'court' => $milimaniHC, 'start' => -45,
                    'description' => 'Constitutional petition challenging approval of a high-rise development without public participation (Articles 10 and 69).', 'opposing' => 'Nairobi City County Government and another', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -20, 'type' => 'Mention', 'time' => '09:00', 'room' => 'Constitutional Division', 'officer' => 'Hon. Justice, Constitutional Division', 'notes' => 'Conservatory orders sought.', 'outcome' => 'Interim conservatory order granted for 30 days.'],
                        ['offset' => 10, 'type' => 'Hearing of Substantive applications', 'time' => '09:00', 'room' => 'Constitutional Division', 'officer' => 'Hon. Justice, Constitutional Division', 'notes' => 'Hearing of the application to extend the order.'],
                    ],
                ],
                [
                    'key' => 'm8', 'client' => 'nyanza', 'lawyer' => 'peter.rotich@wanjiruotieno.co.ke', 'number' => 'ELC Case 142 of 2026', 'type' => 'land', 'court' => $elc, 'start' => -75,
                    'description' => 'Injunction against a chargee selling land charged as security for a loan; redemption account.', 'opposing' => 'Odera Holdings Ltd', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -35, 'type' => 'Interlocutory Application', 'time' => '10:30', 'room' => 'ELC Court 3', 'officer' => 'Hon. Judge, ELC', 'notes' => 'Ex-parte injunction.', 'outcome' => 'Injunction granted pending inter-partes hearing.'],
                        ['offset' => 28, 'type' => 'Ruling', 'time' => '10:30', 'room' => 'ELC Court 3', 'officer' => 'Hon. Judge, ELC', 'notes' => 'Ruling on the injunction.'],
                    ],
                ],
                [
                    'key' => 'm9', 'client' => 'lakeview', 'lawyer' => 'amina.abdi@wanjiruotieno.co.ke', 'number' => 'HCCA E033 of 2026', 'type' => 'appellate', 'court' => $milimaniHC, 'start' => -120,
                    'description' => 'Appeal against the magistrate court judgment on a disputed water and sewerage service contract.', 'opposing' => 'Naivasha Utilities Co. Ltd', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -50, 'type' => 'Case Management conference', 'time' => '09:30', 'room' => 'Court 9', 'officer' => 'Hon. Justice, Civil Appeals', 'notes' => 'Record of appeal filed.', 'outcome' => 'Written submissions directed.'],
                        ['offset' => 35, 'type' => 'Appeal hearing', 'time' => '09:30', 'room' => 'Court 9', 'officer' => 'Hon. Justice, Civil Appeals', 'notes' => 'Highlighting of submissions.'],
                    ],
                ],
            ],
            'tasks' => [
                ['title' => 'File list of documents and witness statements: HCCC E214 of 2026', 'description' => 'Compile the supply agreement, delivery notes and M-Pesa payment records; file and serve before the trial date.', 'assignee' => 'david.odhiambo@wanjiruotieno.co.ke', 'due' => 4, 'priority' => 'high', 'status' => 'in_progress'],
                ['title' => 'Prepare client for evidence in chief', 'description' => 'Mock examination session with Mr Njoroge ahead of the trial.', 'assignee' => 'david.odhiambo@wanjiruotieno.co.ke', 'due' => 5, 'priority' => 'high', 'status' => 'pending'],
                ['title' => 'Official search at the Lands Registry: Machakos/Mwala/3412', 'description' => 'Obtain a certified copy of the register and the green card for the court bundle.', 'assignee' => 'peter.rotich@wanjiruotieno.co.ke', 'due' => 8, 'priority' => 'medium', 'status' => 'pending'],
                ['title' => 'Draft supporting affidavit for summary judgment: CMCC E1876 of 2026', 'description' => 'Annex the signed delivery notes and the unpaid invoices.', 'assignee' => 'amina.abdi@wanjiruotieno.co.ke', 'due' => 1, 'priority' => 'high', 'status' => 'in_progress'],
                ['title' => 'Serve notice of hearing on the objector', 'description' => 'Personal service in Bungoma; file the affidavit of service.', 'assignee' => 'brian.musyoka@wanjiruotieno.co.ke', 'due' => 6, 'priority' => 'medium', 'status' => 'pending'],
                ['title' => 'Follow up bail bond and surety documents: Criminal Case E1203 of 2026', 'description' => 'Confirm the surety\'s ID and the cash bail receipt are on the court file.', 'assignee' => 'brian.musyoka@wanjiruotieno.co.ke', 'due' => 1, 'priority' => 'high', 'status' => 'pending'],
                ['title' => 'Record M-Pesa and bank receipts for the week', 'description' => 'Match receipts against open invoices and update payments.', 'assignee' => 'faith.gathoni@wanjiruotieno.co.ke', 'due' => 2, 'priority' => 'medium', 'status' => 'in_progress'],
                ['title' => 'Review the interim conservatory order and diarise its expiry', 'description' => 'The order lapses 30 days after it was granted; the extension application must be heard before then.', 'assignee' => 'amina.abdi@wanjiruotieno.co.ke', 'due' => 7, 'priority' => 'high', 'status' => 'pending'],
                ['title' => 'Send the closing statement of account to Safari Fresh Produce', 'description' => 'Fees, disbursements and court filing fees to date.', 'assignee' => 'faith.gathoni@wanjiruotieno.co.ke', 'due' => -3, 'priority' => 'low', 'status' => 'overdue'],
            ],
            // time entries: matter key, lawyer, days ago, hours, description
            'time' => [
                ['m1', 'david.odhiambo@wanjiruotieno.co.ke', -12, 2.5, 'Review defence and prepare list of issues'],
                ['m1', 'david.odhiambo@wanjiruotieno.co.ke', -7, 3.0, 'Witness preparation with client'],
                ['m3', 'amina.abdi@wanjiruotieno.co.ke', -10, 1.5, 'Draft application for summary judgment'],
                ['m3', 'amina.abdi@wanjiruotieno.co.ke', -4, 2.0, 'Research on Order 36, Civil Procedure Rules'],
                ['m4', 'amina.abdi@wanjiruotieno.co.ke', -15, 2.0, 'Prepare witness statement for HR manager'],
                ['m2', 'peter.rotich@wanjiruotieno.co.ke', -9, 3.5, 'Site visit and meeting with the county surveyor'],
                ['m8', 'peter.rotich@wanjiruotieno.co.ke', -20, 2.0, 'Review loan agreement and charge instrument'],
                ['m5', 'mercy.onyango@wanjiruotieno.co.ke', -8, 2.5, 'Draft affidavit in reply to the protest'],
                ['m6', 'david.odhiambo@wanjiruotieno.co.ke', -18, 1.5, 'Attend bail review and take instructions'],
                ['m7', 'amina.abdi@wanjiruotieno.co.ke', -22, 4.0, 'Prepare petition and supporting affidavit'],
            ],
            // invoices: matter key, days ago issued, pay terms (days), lines [description, qty, rate KES], paid KES (null = unpaid)
            'invoices' => [
                ['m3', -35, 14, [['Professional fees: pleadings and attendances', 1, 85000], ['Court filing fees (disbursement)', 1, 12000]], 'full'],
                ['m1', -28, 14, [['Professional fees: plaint, list of documents and pre-trial attendances', 1, 180000], ['Court filing and service fees (disbursement)', 1, 24000]], 100000],
                ['m4', -20, 14, [['Professional fees: preparation for hearing and attendance', 1, 120000]], null],
                ['m2', -12, 14, [['Professional fees: site visit, surveyor liaison and case management', 1, 95000], ['Official search fees (disbursement)', 1, 6000]], null],
                ['m7', -8, 14, [['Professional fees: petition, affidavits and conservatory application', 1, 260000]], null],
            ],
        ];
    }

    private static function coastLegal(): array
    {
        $hc = 'High Court at Mombasa';
        $elc = 'Environment and Land Court at Mombasa';
        $cmc = "Chief Magistrate's Court at Mombasa";
        $kadhi = "Kadhi's Court at Mombasa";

        return [
            'slug' => 'coast-legal',
            'name' => 'Coast Legal Partners',
            'domain' => 'coast-legal.localhost',
            'extra_domains' => [],
            'plan' => 'professional',
            'subscription' => ['plan' => 'professional', 'paid' => false],
            'color' => '#0e7490',
            'letterhead' => [
                'address' => '2nd Floor, Nyali Centre, Links Road, Mombasa', 'phone' => '+254 41 555 0188', 'email' => 'chambers@coastlegal.co.ke',
                'website' => 'www.coastlegal.co.ke', 'tax_pin' => 'P052345678L',
            ],
            'invoice_footer' => 'Payment terms: 14 days. Pay by M-Pesa Paybill (number on request), account: invoice number. All fees are subject to VAT at 16%.',
            'users' => [
                ['name' => 'Hassan Mohamed Said', 'email' => 'hassan.said@coastlegal.co.ke', 'role' => 'admin', 'title' => 'Managing Partner', 'phone' => '0700300301', 'hired' => '2010-08-01', 'password' => 'Law@Hassan2026!'],
                ['name' => 'Fatuma Bakari Juma', 'email' => 'fatuma.juma@coastlegal.co.ke', 'role' => 'advocate', 'title' => 'Senior Associate', 'phone' => '0700300302', 'hired' => '2017-05-15', 'password' => 'Law@Fatuma2026!', 'rate' => 1500000],
                ['name' => 'Salim Kassim Mwinyi', 'email' => 'salim.mwinyi@coastlegal.co.ke', 'role' => 'advocate', 'title' => 'Associate', 'phone' => '0700300303', 'hired' => '2021-03-01', 'password' => 'Law@Salim2026!', 'rate' => 1100000],
                ['name' => 'Rose Mwende Kioko', 'email' => 'rose.kioko@coastlegal.co.ke', 'role' => 'clerk', 'title' => 'Court Clerk', 'phone' => '0700300304', 'hired' => '2022-07-18', 'password' => 'Law@Rose2026!'],
            ],
            'clients' => [
                'dhow' => ['name' => 'Mombasa Dhow Traders Ltd', 'phone' => '0700400401', 'extra' => null, 'address' => 'Old Port Road, Mombasa', 'advocate' => 'fatuma.juma@coastlegal.co.ke'],
                'khadija' => ['name' => 'Khadija Omar Athman', 'phone' => '0700400402', 'extra' => null, 'address' => 'Majengo, Mombasa', 'advocate' => 'salim.mwinyi@coastlegal.co.ke'],
                'likoni' => ['name' => 'Likoni Ferry Vendors Association', 'phone' => '0700400403', 'extra' => null, 'address' => 'Likoni, Mombasa', 'advocate' => 'fatuma.juma@coastlegal.co.ke'],
            ],
            'matters' => [
                ['key' => 'c1', 'client' => 'dhow', 'lawyer' => 'fatuma.juma@coastlegal.co.ke', 'number' => 'HCCOMM E019 of 2026', 'type' => 'commercial', 'court' => $hc, 'start' => -110,
                    'description' => 'Cargo claim of KSh 7.5 million for goods damaged in transit through the Port of Mombasa.', 'opposing' => 'Indian Ocean Freight Forwarders Ltd', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -40, 'type' => 'Case Management conference', 'time' => '09:00', 'room' => 'Court 2', 'officer' => 'Hon. Justice, Commercial Division', 'notes' => 'Timetable for exchange of documents.', 'outcome' => 'Documents to be exchanged in 30 days.'],
                        ['offset' => 14, 'type' => 'Pretrial Conference', 'time' => '09:00', 'room' => 'Court 2', 'officer' => 'Hon. Justice, Commercial Division', 'notes' => 'Compliance check.'],
                    ]],
                ['key' => 'c2', 'client' => 'khadija', 'lawyer' => 'salim.mwinyi@coastlegal.co.ke', 'number' => 'Succession Cause 12 of 2026', 'type' => 'family', 'court' => $kadhi, 'start' => -80,
                    'description' => 'Distribution of the estate of the late Omar Athman Bakari under Islamic law.', 'opposing' => 'Not applicable (uncontested)', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => 5, 'type' => 'Mention', 'time' => '10:00', 'room' => 'Kadhi Court', 'officer' => 'Hon. Kadhi', 'notes' => 'Confirmation of heirs.'],
                    ]],
                ['key' => 'c3', 'client' => 'likoni', 'lawyer' => 'fatuma.juma@coastlegal.co.ke', 'number' => 'ELC Case 54 of 2026', 'type' => 'land', 'court' => $elc, 'start' => -65,
                    'description' => 'Protection of the vendors\' trading plots against eviction from the ferry terminal frontage.', 'opposing' => 'The County Government of Mombasa and another', 'station' => 'Likoni',
                    'hearings' => [
                        ['offset' => -25, 'type' => 'Interlocutory Application', 'time' => '10:00', 'room' => 'ELC Court 1', 'officer' => 'Hon. Judge, ELC', 'notes' => 'Prayer for interim orders.', 'outcome' => 'Status quo order issued.'],
                        ['offset' => 18, 'type' => 'Hearing of Substantive applications', 'time' => '10:00', 'room' => 'ELC Court 1', 'officer' => 'Hon. Judge, ELC', 'notes' => 'Hearing of the injunction application.'],
                    ]],
                ['key' => 'c4', 'client' => 'khadija', 'lawyer' => 'salim.mwinyi@coastlegal.co.ke', 'number' => 'Criminal Case E455 of 2026', 'type' => 'criminal', 'court' => $cmc, 'start' => -30,
                    'description' => 'Defence of a relative charged with a traffic offence causing bodily injury.', 'opposing' => 'Republic (Office of the Director of Public Prosecutions)', 'station' => 'Central',
                    'hearings' => [
                        ['offset' => 4, 'type' => 'Pretrial Conference', 'time' => '09:00', 'room' => 'Court 3', 'officer' => 'Principal Magistrate', 'notes' => 'Disclosure.'],
                    ]],
            ],
            'tasks' => [
                ['title' => 'Prepare bundle of documents: HCCOMM E019 of 2026', 'description' => 'Bills of lading, survey report and correspondence with the forwarder.', 'assignee' => 'fatuma.juma@coastlegal.co.ke', 'due' => 9, 'priority' => 'high', 'status' => 'in_progress'],
                ['title' => 'Confirm the heirs\' identity documents for the Kadhi\'s Court', 'description' => 'Certified copies of national IDs and the death certificate.', 'assignee' => 'rose.kioko@coastlegal.co.ke', 'due' => 3, 'priority' => 'medium', 'status' => 'pending'],
                ['title' => 'Draft replying affidavit for the County\'s application', 'description' => 'Annex the vendors\' licences and allotment letters.', 'assignee' => 'fatuma.juma@coastlegal.co.ke', 'due' => 12, 'priority' => 'high', 'status' => 'pending'],
            ],
            'time' => [],
            'invoices' => [],
        ];
    }

    private static function riftValley(): array
    {
        $hc = 'High Court at Eldoret';
        $elc = 'Environment and Land Court at Eldoret';
        $cmc = "Chief Magistrate's Court at Eldoret";

        return [
            'slug' => 'rift-valley-advocates',
            'name' => 'Rift Valley Advocates LLP',
            'domain' => 'rift-valley-advocates.localhost',
            'extra_domains' => [],
            'plan' => 'trial',
            'subscription' => ['plan' => 'trial', 'paid' => false],
            'color' => '#15803d',
            'letterhead' => [
                'address' => 'Ground Floor, Uasin Gishu Plaza, Uganda Road, Eldoret', 'phone' => '+254 53 555 0121', 'email' => 'office@riftvalleyadvocates.co.ke',
                'website' => 'www.riftvalleyadvocates.co.ke', 'tax_pin' => 'P053456789M',
            ],
            'invoice_footer' => 'Payment terms: 14 days. Pay by M-Pesa Paybill (number on request), account: invoice number. All fees are subject to VAT at 16%.',
            'users' => [
                ['name' => 'Joseph Kipchoge Cheruiyot', 'email' => 'joseph.cheruiyot@riftvalleyadvocates.co.ke', 'role' => 'admin', 'title' => 'Senior Partner', 'phone' => '0700500501', 'hired' => '2015-01-12', 'password' => 'Law@Joseph2026!'],
                ['name' => 'Naomi Chebet Koech', 'email' => 'naomi.koech@riftvalleyadvocates.co.ke', 'role' => 'advocate', 'title' => 'Associate', 'phone' => '0700500502', 'hired' => '2020-09-07', 'password' => 'Law@Naomi2026!', 'rate' => 1000000],
                ['name' => 'Samuel Kimani Ngugi', 'email' => 'samuel.ngugi@riftvalleyadvocates.co.ke', 'role' => 'clerk', 'title' => 'Court Clerk', 'phone' => '0700500503', 'hired' => '2023-02-20', 'password' => 'Law@Samuel2026!'],
            ],
            'clients' => [
                'dairy' => ['name' => 'Kapsabet Dairy Farmers Co-operative Society', 'phone' => '0700600601', 'extra' => null, 'address' => 'Kapsabet, Nandi County', 'advocate' => 'naomi.koech@riftvalleyadvocates.co.ke'],
                'tarus' => ['name' => 'Daniel Kiprop Tarus', 'phone' => '0700600602', 'extra' => null, 'address' => 'Moiben, Uasin Gishu County', 'advocate' => 'naomi.koech@riftvalleyadvocates.co.ke'],
            ],
            'matters' => [
                ['key' => 'r1', 'client' => 'tarus', 'lawyer' => 'naomi.koech@riftvalleyadvocates.co.ke', 'number' => 'ELC Case 33 of 2026', 'type' => 'land', 'court' => $elc, 'start' => -50,
                    'description' => 'Claim to a 5-acre parcel by adverse possession of more than 12 years.', 'opposing' => 'Jepkemei Rotich', 'station' => 'Moiben',
                    'hearings' => [
                        ['offset' => 7, 'type' => 'Case Management conference', 'time' => '09:30', 'room' => 'ELC Court', 'officer' => 'Hon. Judge, ELC', 'notes' => 'Directions on witnesses and a site visit.'],
                    ]],
                ['key' => 'r2', 'client' => 'dairy', 'lawyer' => 'naomi.koech@riftvalleyadvocates.co.ke', 'number' => 'HCCC E041 of 2026', 'type' => 'commercial', 'court' => $hc, 'start' => -70,
                    'description' => 'Recovery of KSh 3.1 million for milk delivered to a processor and not paid for.', 'opposing' => 'Highland Milk Processors Ltd', 'station' => 'Not applicable',
                    'hearings' => [
                        ['offset' => -20, 'type' => 'Mention', 'time' => '09:00', 'room' => 'Court 1', 'officer' => 'Hon. Justice', 'notes' => 'Defence filed.', 'outcome' => 'Parties to explore settlement.'],
                        ['offset' => 15, 'type' => 'Pretrial Conference', 'time' => '09:00', 'room' => 'Court 1', 'officer' => 'Hon. Justice', 'notes' => 'Report on settlement talks.'],
                    ]],
                ['key' => 'r3', 'client' => 'tarus', 'lawyer' => 'naomi.koech@riftvalleyadvocates.co.ke', 'number' => 'Criminal Case E208 of 2026', 'type' => 'criminal', 'court' => $cmc, 'start' => -25,
                    'description' => 'Defence on a charge of malicious damage to property.', 'opposing' => 'Republic (Office of the Director of Public Prosecutions)', 'station' => 'Eldoret Central',
                    'hearings' => [
                        ['offset' => 3, 'type' => 'Plea Taking', 'time' => '09:00', 'room' => 'Court 2', 'officer' => 'Senior Resident Magistrate', 'notes' => 'Plea.'],
                    ]],
            ],
            'tasks' => [
                ['title' => 'Prepare the demand letter follow-up for Highland Milk Processors', 'description' => 'Settlement proposal for the client\'s approval.', 'assignee' => 'naomi.koech@riftvalleyadvocates.co.ke', 'due' => 5, 'priority' => 'high', 'status' => 'pending'],
                ['title' => 'Obtain the chief\'s letter and survey map: ELC Case 33 of 2026', 'description' => 'Evidence of the client\'s occupation since 2012.', 'assignee' => 'samuel.ngugi@riftvalleyadvocates.co.ke', 'due' => 6, 'priority' => 'medium', 'status' => 'pending'],
            ],
            'time' => [],
            'invoices' => [],
        ];
    }
}
