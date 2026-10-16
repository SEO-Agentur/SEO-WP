<?php
/**
 * The Schema.org types that make sense for a local business.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema;

/**
 * Schema.org subtypes of LocalBusiness, grouped the way Schema.org groups
 * them, for the "Business type" dropdown. Every key is a real Schema.org
 * type name, so whatever is picked is emitted verbatim as the node's
 * "@type".
 */
final class LocalBusinessTypes {

	/**
	 * Group label => type name => human label.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function groups(): array {
		return array(
			__( 'General', 'seooptimierung-toolkit' )    => array(
				'LocalBusiness'            => __( 'Local business (generic)', 'seooptimierung-toolkit' ),
				'ProfessionalService'      => __( 'Professional service', 'seooptimierung-toolkit' ),
				'EmploymentAgency'         => __( 'Employment agency', 'seooptimierung-toolkit' ),
				'GovernmentOffice'         => __( 'Government office', 'seooptimierung-toolkit' ),
				'InternetCafe'             => __( 'Internet café', 'seooptimierung-toolkit' ),
				'Library'                  => __( 'Library', 'seooptimierung-toolkit' ),
				'RecyclingCenter'          => __( 'Recycling center', 'seooptimierung-toolkit' ),
				'SelfStorage'              => __( 'Self storage', 'seooptimierung-toolkit' ),
				'ChildCare'                => __( 'Child care', 'seooptimierung-toolkit' ),
				'AnimalShelter'            => __( 'Animal shelter', 'seooptimierung-toolkit' ),
				'DryCleaningOrLaundry'     => __( 'Dry cleaning or laundry', 'seooptimierung-toolkit' ),
				'TouristInformationCenter' => __( 'Tourist information center', 'seooptimierung-toolkit' ),
			),
			__( 'Food and drink', 'seooptimierung-toolkit' ) => array(
				'FoodEstablishment'  => __( 'Food establishment (generic)', 'seooptimierung-toolkit' ),
				'Restaurant'         => __( 'Restaurant', 'seooptimierung-toolkit' ),
				'CafeOrCoffeeShop'   => __( 'Café or coffee shop', 'seooptimierung-toolkit' ),
				'Bakery'             => __( 'Bakery', 'seooptimierung-toolkit' ),
				'BarOrPub'           => __( 'Bar or pub', 'seooptimierung-toolkit' ),
				'Brewery'            => __( 'Brewery', 'seooptimierung-toolkit' ),
				'Distillery'         => __( 'Distillery', 'seooptimierung-toolkit' ),
				'Winery'             => __( 'Winery', 'seooptimierung-toolkit' ),
				'FastFoodRestaurant' => __( 'Fast food restaurant', 'seooptimierung-toolkit' ),
				'IceCreamShop'       => __( 'Ice cream shop', 'seooptimierung-toolkit' ),
			),
			__( 'Legal and financial', 'seooptimierung-toolkit' ) => array(
				'LegalService'      => __( 'Legal service', 'seooptimierung-toolkit' ),
				'Attorney'          => __( 'Attorney', 'seooptimierung-toolkit' ),
				'Notary'            => __( 'Notary', 'seooptimierung-toolkit' ),
				'FinancialService'  => __( 'Financial service', 'seooptimierung-toolkit' ),
				'AccountingService' => __( 'Accounting service', 'seooptimierung-toolkit' ),
				'BankOrCreditUnion' => __( 'Bank or credit union', 'seooptimierung-toolkit' ),
				'InsuranceAgency'   => __( 'Insurance agency', 'seooptimierung-toolkit' ),
				'AutomatedTeller'   => __( 'Automated teller (ATM)', 'seooptimierung-toolkit' ),
			),
			__( 'Health and medical', 'seooptimierung-toolkit' ) => array(
				'MedicalBusiness' => __( 'Medical business (generic)', 'seooptimierung-toolkit' ),
				'Physician'       => __( 'Physician', 'seooptimierung-toolkit' ),
				'Dentist'         => __( 'Dentist', 'seooptimierung-toolkit' ),
				'MedicalClinic'   => __( 'Medical clinic', 'seooptimierung-toolkit' ),
				'Pharmacy'        => __( 'Pharmacy', 'seooptimierung-toolkit' ),
				'Optician'        => __( 'Optician', 'seooptimierung-toolkit' ),
				'Physiotherapy'   => __( 'Physiotherapy', 'seooptimierung-toolkit' ),
				'DietNutrition'   => __( 'Diet and nutrition', 'seooptimierung-toolkit' ),
				'Hospital'        => __( 'Hospital', 'seooptimierung-toolkit' ),
				'CommunityHealth' => __( 'Community health', 'seooptimierung-toolkit' ),
				'PlasticSurgery'  => __( 'Plastic surgery', 'seooptimierung-toolkit' ),
				'Psychiatric'     => __( 'Psychiatric', 'seooptimierung-toolkit' ),
				'Dermatology'     => __( 'Dermatology', 'seooptimierung-toolkit' ),
				'Pediatric'       => __( 'Pediatric', 'seooptimierung-toolkit' ),
				'Podiatric'       => __( 'Podiatric', 'seooptimierung-toolkit' ),
			),
			__( 'Beauty, wellness and sport', 'seooptimierung-toolkit' ) => array(
				'HealthAndBeautyBusiness' => __( 'Health and beauty business (generic)', 'seooptimierung-toolkit' ),
				'HairSalon'               => __( 'Hair salon', 'seooptimierung-toolkit' ),
				'BeautySalon'             => __( 'Beauty salon', 'seooptimierung-toolkit' ),
				'NailSalon'               => __( 'Nail salon', 'seooptimierung-toolkit' ),
				'DaySpa'                  => __( 'Day spa', 'seooptimierung-toolkit' ),
				'TattooParlor'            => __( 'Tattoo parlor', 'seooptimierung-toolkit' ),
				'HealthClub'              => __( 'Health club', 'seooptimierung-toolkit' ),
				'ExerciseGym'             => __( 'Gym', 'seooptimierung-toolkit' ),
				'SportsClub'              => __( 'Sports club', 'seooptimierung-toolkit' ),
				'SportsActivityLocation'  => __( 'Sports activity location', 'seooptimierung-toolkit' ),
				'GolfCourse'              => __( 'Golf course', 'seooptimierung-toolkit' ),
				'TennisComplex'           => __( 'Tennis complex', 'seooptimierung-toolkit' ),
				'BowlingAlley'            => __( 'Bowling alley', 'seooptimierung-toolkit' ),
				'PublicSwimmingPool'      => __( 'Public swimming pool', 'seooptimierung-toolkit' ),
				'SkiResort'               => __( 'Ski resort', 'seooptimierung-toolkit' ),
				'StadiumOrArena'          => __( 'Stadium or arena', 'seooptimierung-toolkit' ),
			),
			__( 'Home, construction and trades', 'seooptimierung-toolkit' ) => array(
				'HomeAndConstructionBusiness' => __( 'Home and construction business (generic)', 'seooptimierung-toolkit' ),
				'GeneralContractor'           => __( 'General contractor', 'seooptimierung-toolkit' ),
				'Electrician'                 => __( 'Electrician', 'seooptimierung-toolkit' ),
				'Plumber'                     => __( 'Plumber', 'seooptimierung-toolkit' ),
				'HVACBusiness'                => __( 'Heating and air conditioning (HVAC)', 'seooptimierung-toolkit' ),
				'RoofingContractor'           => __( 'Roofing contractor', 'seooptimierung-toolkit' ),
				'HousePainter'                => __( 'House painter', 'seooptimierung-toolkit' ),
				'Locksmith'                   => __( 'Locksmith', 'seooptimierung-toolkit' ),
				'MovingCompany'               => __( 'Moving company', 'seooptimierung-toolkit' ),
			),
			__( 'Real estate and travel', 'seooptimierung-toolkit' ) => array(
				'RealEstateAgent' => __( 'Real estate agent', 'seooptimierung-toolkit' ),
				'TravelAgency'    => __( 'Travel agency', 'seooptimierung-toolkit' ),
				'LodgingBusiness' => __( 'Lodging business (generic)', 'seooptimierung-toolkit' ),
				'Hotel'           => __( 'Hotel', 'seooptimierung-toolkit' ),
				'Motel'           => __( 'Motel', 'seooptimierung-toolkit' ),
				'Hostel'          => __( 'Hostel', 'seooptimierung-toolkit' ),
				'BedAndBreakfast' => __( 'Bed and breakfast', 'seooptimierung-toolkit' ),
				'Resort'          => __( 'Resort', 'seooptimierung-toolkit' ),
				'Campground'      => __( 'Campground', 'seooptimierung-toolkit' ),
				'VacationRental'  => __( 'Vacation rental', 'seooptimierung-toolkit' ),
			),
			__( 'Shops', 'seooptimierung-toolkit' )      => array(
				'Store'                => __( 'Store (generic)', 'seooptimierung-toolkit' ),
				'GroceryStore'         => __( 'Grocery store', 'seooptimierung-toolkit' ),
				'ConvenienceStore'     => __( 'Convenience store', 'seooptimierung-toolkit' ),
				'ClothingStore'        => __( 'Clothing store', 'seooptimierung-toolkit' ),
				'ShoeStore'            => __( 'Shoe store', 'seooptimierung-toolkit' ),
				'JewelryStore'         => __( 'Jewelry store', 'seooptimierung-toolkit' ),
				'ElectronicsStore'     => __( 'Electronics store', 'seooptimierung-toolkit' ),
				'ComputerStore'        => __( 'Computer store', 'seooptimierung-toolkit' ),
				'MobilePhoneStore'     => __( 'Mobile phone store', 'seooptimierung-toolkit' ),
				'FurnitureStore'       => __( 'Furniture store', 'seooptimierung-toolkit' ),
				'HomeGoodsStore'       => __( 'Home goods store', 'seooptimierung-toolkit' ),
				'HardwareStore'        => __( 'Hardware store', 'seooptimierung-toolkit' ),
				'GardenStore'          => __( 'Garden store', 'seooptimierung-toolkit' ),
				'Florist'              => __( 'Florist', 'seooptimierung-toolkit' ),
				'BookStore'            => __( 'Book store', 'seooptimierung-toolkit' ),
				'ToyStore'             => __( 'Toy store', 'seooptimierung-toolkit' ),
				'PetStore'             => __( 'Pet store', 'seooptimierung-toolkit' ),
				'BikeStore'            => __( 'Bike store', 'seooptimierung-toolkit' ),
				'SportingGoodsStore'   => __( 'Sporting goods store', 'seooptimierung-toolkit' ),
				'LiquorStore'          => __( 'Liquor store', 'seooptimierung-toolkit' ),
				'DepartmentStore'      => __( 'Department store', 'seooptimierung-toolkit' ),
				'ShoppingCenter'       => __( 'Shopping center', 'seooptimierung-toolkit' ),
				'OfficeEquipmentStore' => __( 'Office equipment store', 'seooptimierung-toolkit' ),
				'WholesaleStore'       => __( 'Wholesale store', 'seooptimierung-toolkit' ),
			),
			__( 'Automotive', 'seooptimierung-toolkit' ) => array(
				'AutomotiveBusiness' => __( 'Automotive business (generic)', 'seooptimierung-toolkit' ),
				'AutoRepair'         => __( 'Auto repair', 'seooptimierung-toolkit' ),
				'AutoBodyShop'       => __( 'Auto body shop', 'seooptimierung-toolkit' ),
				'AutoDealer'         => __( 'Auto dealer', 'seooptimierung-toolkit' ),
				'AutoRental'         => __( 'Auto rental', 'seooptimierung-toolkit' ),
				'AutoWash'           => __( 'Car wash', 'seooptimierung-toolkit' ),
				'AutoPartsStore'     => __( 'Auto parts store', 'seooptimierung-toolkit' ),
				'GasStation'         => __( 'Gas station', 'seooptimierung-toolkit' ),
				'MotorcycleDealer'   => __( 'Motorcycle dealer', 'seooptimierung-toolkit' ),
				'MotorcycleRepair'   => __( 'Motorcycle repair', 'seooptimierung-toolkit' ),
				'TireShop'           => __( 'Tire shop', 'seooptimierung-toolkit' ),
			),
			__( 'Entertainment and media', 'seooptimierung-toolkit' ) => array(
				'EntertainmentBusiness' => __( 'Entertainment business (generic)', 'seooptimierung-toolkit' ),
				'MovieTheater'          => __( 'Movie theater', 'seooptimierung-toolkit' ),
				'NightClub'             => __( 'Night club', 'seooptimierung-toolkit' ),
				'ArtGallery'            => __( 'Art gallery', 'seooptimierung-toolkit' ),
				'AmusementPark'         => __( 'Amusement park', 'seooptimierung-toolkit' ),
				'ComedyClub'            => __( 'Comedy club', 'seooptimierung-toolkit' ),
				'Casino'                => __( 'Casino', 'seooptimierung-toolkit' ),
				'RadioStation'          => __( 'Radio station', 'seooptimierung-toolkit' ),
				'TelevisionStation'     => __( 'Television station', 'seooptimierung-toolkit' ),
			),
			__( 'Emergency services', 'seooptimierung-toolkit' ) => array(
				'EmergencyService' => __( 'Emergency service (generic)', 'seooptimierung-toolkit' ),
				'FireStation'      => __( 'Fire station', 'seooptimierung-toolkit' ),
				'PoliceStation'    => __( 'Police station', 'seooptimierung-toolkit' ),
			),
		);
	}

	/**
	 * Flat type name => human label, across all groups.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		$all = array();

		foreach ( self::groups() as $types ) {
			$all = array_merge( $all, $types );
		}

		return $all;
	}
}
