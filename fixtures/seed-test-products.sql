-- Test data: 15 extra storefront products covering every frontend category.
--
-- Run (from the backend folder, Docker only):
--   sudo docker compose exec -T database psql -v ON_ERROR_STOP=1 -U app -d app < fixtures/seed-test-products.sql
--
-- Safe to re-run: rows are skipped when the slug already exists (ON CONFLICT DO NOTHING).
-- Prices are whole dinars, exactly like the products seeded by the migrations
-- (the *_minor column names are historical: 680 means 680 DT).
-- Subcategory values match the labels of the frontend taxonomy (Version20261018000000).

BEGIN;

INSERT INTO storefront_brand (name) VALUES ('Hansgrohe') ON CONFLICT DO NOTHING;

INSERT INTO storefront_product_view (
    id, slug, name, brand, ref, category_slug, subcategory, price_minor, old_price_minor, currency,
    stock, material, dims, image_path, rating, reviews, is_new, is_best, headrest, description, variants
) VALUES
-- piscine (3)
(gen_random_uuid(), 'pompe-filtration-piscine', 'Pompe de filtration 0,75 CV', 'Sopal', 'SOP-PF075', 'piscine', 'Filtration',
 520, 590, 'TND', 'in', 'Polypropylène', '0,75 - 1 CV', '/p-tools.jpg', 4.4, 19, false, true, false,
 'Pompe auto-amorçante à préfiltre intégré, silencieuse, pour bassins jusqu''à 40 m³.',
 '[{"label":"0,75 CV","price":520},{"label":"1 CV","price":610}]'::json),
(gen_random_uuid(), 'robot-nettoyeur-piscine', 'Robot nettoyeur de piscine', 'Sanibel', 'SAN-RB200', 'piscine', 'Nettoyage',
 1890, NULL, 'TND', 'order', 'ABS', 'Câble 12 m', '/p-tools.jpg', 4.6, 11, true, false, false,
 'Robot électrique autonome pour fond et parois, cycle de nettoyage de 2 heures, filtre facile à vider.',
 '[]'::json),
(gen_random_uuid(), 'chlore-galets-5kg', 'Chlore en galets 5 kg', 'Sopal', 'SOP-CL5', 'piscine', 'Produits d''entretien piscine',
 125, NULL, 'TND', 'in', 'Trichloroisocyanurique', '5 kg', '/p-tools.jpg', 4.2, 64, false, false, false,
 'Galets de chlore lent de 250 g, désinfection longue durée de l''eau de piscine.',
 '[]'::json),

-- drainage (2)
(gen_random_uuid(), 'caniveau-douche-inox', 'Caniveau de douche inox', 'Geberit', 'GEB-CD70', 'drainage', 'Caniveaux de douche',
 310, NULL, 'TND', 'in', 'Inox 304', '70-90 cm', '/p-shower.jpg', 4.7, 23, false, true, false,
 'Caniveau de douche à l''italienne, grille réversible et siphon à garde d''eau intégré.',
 '[{"label":"70 cm","price":310},{"label":"80 cm","price":340},{"label":"90 cm","price":375}]'::json),
(gen_random_uuid(), 'siphon-sol-exterieur', 'Siphon de sol extérieur', 'Sanibel', 'SAN-SE110', 'drainage', 'Siphon extérieur',
 48, NULL, 'TND', 'in', 'PVC', 'Ø 110 mm', '/p-tools.jpg', 4.0, 37, false, false, false,
 'Siphon de cour avec panier amovible et grille anti-feuilles, sortie verticale.',
 '[]'::json),

-- pompe (1)
(gen_random_uuid(), 'broyeur-sanitaire-600w', 'Broyeur sanitaire 600 W', 'Sanibel', 'SAN-BR600', 'pompe', 'Broyeur',
 780, 890, 'TND', 'in', 'Inox et ABS', '600 W', '/p-wc.jpg', 4.5, 29, false, true, false,
 'Broyeur pour WC, lavabo et douche, refoulement jusqu''à 5 m de hauteur et 100 m à l''horizontale.',
 '[]'::json),

-- electricite (2)
(gen_random_uuid(), 'spot-led-encastrable', 'Spot LED encastrable 7 W', 'Sopal', 'SOP-LED7', 'electricite', 'Eco led',
 14, NULL, 'TND', 'in', 'Aluminium', 'Ø 85 mm', '/p-tools.jpg', 4.1, 92, true, false, false,
 'Spot encastrable blanc neutre 4000 K, 600 lm, durée de vie 25 000 heures.',
 '[{"label":"Lot de 1","price":14},{"label":"Lot de 6","price":78},{"label":"Lot de 10","price":120}]'::json),
(gen_random_uuid(), 'rail-supportage-galvanise', 'Rail de supportage galvanisé', 'Sopal', 'SOP-RS200', 'electricite', 'Supportage',
 22, NULL, 'TND', 'pickup', 'Acier galvanisé', 'Longueur 2 m', '/p-tools.jpg', 3.9, 15, false, false, false,
 'Rail de fixation pour câbles et tubes, perforations régulières, galvanisation à chaud.',
 '[]'::json),

-- traitement-des-eaux (2)
(gen_random_uuid(), 'osmoseur-5-etapes', 'Osmoseur domestique 5 étapes', 'Sanibel', 'SAN-OS5', 'traitement-des-eaux', 'Osmoseur',
 690, 790, 'TND', 'out', 'Plastique alimentaire', 'Sous évier', '/p-faucet.jpg', 4.3, 18, false, false, false,
 'Osmose inverse 5 étapes avec robinet dédié, réduction du chlore, du calcaire et des métaux.',
 '[]'::json),
(gen_random_uuid(), 'adoucisseur-eau-20l', 'Adoucisseur d''eau 20 L', 'Sopal', 'SOP-AD20', 'traitement-des-eaux', 'Adoucisseur',
 1450, NULL, 'TND', 'order', 'Composite', 'H 70 cm', '/p-tools.jpg', 4.6, 12, true, false, false,
 'Adoucisseur volumétrique à régénération automatique, protège canalisations et chauffe-eau du calcaire.',
 '[]'::json),

-- chauffage-par-le-sol (1)
(gen_random_uuid(), 'kit-plancher-chauffant', 'Kit plancher chauffant', 'Sopal', 'SOP-PC05', 'chauffage-par-le-sol', 'Plancher chauffant',
 720, NULL, 'TND', 'in', 'Câble chauffant', '5-15 m²', '/p-radiator.jpg', 4.4, 8, false, false, false,
 'Kit complet câble chauffant, thermostat programmable et sonde de sol, pose sous carrelage.',
 '[{"label":"5 m²","price":720},{"label":"10 m²","price":1250},{"label":"15 m²","price":1790}]'::json),

-- chauffage (2)
(gen_random_uuid(), 'chauffe-eau-electrique', 'Chauffe-eau électrique', 'Sopal', 'SOP-CE50', 'chauffage', 'Chauffe eau électrique',
 385, 450, 'TND', 'in', 'Acier émaillé', 'Vertical', '/p-radiator.jpg', 4.5, 41, false, true, false,
 'Cuve émaillée, résistance blindée, thermostat réglable et anode anticorrosion.',
 '[{"label":"50 L","price":385},{"label":"80 L","price":470},{"label":"100 L","price":540}]'::json),
(gen_random_uuid(), 'thermostat-programmable-wifi', 'Thermostat programmable Wi-Fi', 'Sanibel', 'SAN-TH20', 'chauffage', 'Programmable',
 195, NULL, 'TND', 'in', 'ABS', 'Écran tactile', '/p-radiator.jpg', 4.2, 26, true, false, false,
 'Programmation hebdomadaire et pilotage depuis smartphone, compatible chaudières et chauffe-eau.',
 '[]'::json),

-- robinetterie (1)
(gen_random_uuid(), 'mitigeur-lavabo-hansgrohe', 'Mitigeur lavabo Focus', 'Hansgrohe', 'HAN-FO100', 'robinetterie', 'HANSGROHE',
 360, NULL, 'TND', 'in', 'Laiton chromé', 'H 23 cm', '/p-faucet.jpg', 4.8, 34, false, true, false,
 'Mitigeur monocommande avec cartouche céramique et limiteur de débit pour économiser l''eau.',
 '[]'::json),

-- plomberie (1)
(gen_random_uuid(), 'tube-cuivre-recuit-15', 'Tube cuivre recuit Ø 15 mm', 'Sopal', 'SOP-CU15', 'plomberie', 'Cuivre',
 95, NULL, 'TND', 'in', 'Cuivre recuit', 'Couronne 10-25 m', '/p-tools.jpg', 4.3, 20, false, false, false,
 'Tube cuivre recuit en couronne pour eau sanitaire et chauffage, épaisseur 1 mm.',
 '[{"label":"Couronne 10 m","price":95},{"label":"Couronne 25 m","price":225}]'::json)
ON CONFLICT (slug) DO NOTHING;

-- spec-run-8: these are demo rows, not submissions. The approval workflow defaults every new
-- row to PENDING_APPROVAL and unpublished, so the fixture marks its own rows approved and
-- published to keep the storefront filled (spec-run-8 assumption 11).
UPDATE storefront_product_view
   SET approval_status = 'APPROVED', published = true
 WHERE slug IN (
   'pompe-filtration-piscine','robot-nettoyeur-piscine','chlore-galets-5kg','caniveau-douche-inox',
   'siphon-sol-exterieur','broyeur-sanitaire-600w','spot-led-encastrable','rail-supportage-galvanise',
   'osmoseur-5-etapes','adoucisseur-eau-20l','kit-plancher-chauffant','chauffe-eau-electrique',
   'thermostat-programmable-wifi','mitigeur-lavabo-hansgrohe','tube-cuivre-recuit-15');

COMMIT;

-- To remove exactly these test products again:
-- DELETE FROM storefront_product_view WHERE slug IN (
--   'pompe-filtration-piscine','robot-nettoyeur-piscine','chlore-galets-5kg','caniveau-douche-inox',
--   'siphon-sol-exterieur','broyeur-sanitaire-600w','spot-led-encastrable','rail-supportage-galvanise',
--   'osmoseur-5-etapes','adoucisseur-eau-20l','kit-plancher-chauffant','chauffe-eau-electrique',
--   'thermostat-programmable-wifi','mitigeur-lavabo-hansgrohe','tube-cuivre-recuit-15');
