describe('Unit: DateFactory', function() {

    var Factory;

    beforeEach(module('angular-date'));

    beforeEach(inject(function(DateFactory) {
        Factory = DateFactory;
    }));

    it('Should be defined.', function() {
        expect(Factory).toBeDefined();
    });

    it('Moment.js should be included', function() {
        expect(Factory.moment).toBeDefined();
    });

});